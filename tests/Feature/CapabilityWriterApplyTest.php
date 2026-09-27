<?php

namespace Tests\Feature;

use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use App\Models\User;
use App\Support\CapabilityWriter;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * CapabilityWriter::apply(), the guarded writer for an organisation that
 * already exists (Studio W2 S7, R6, R7): exactly the keys sent, each stored
 * and ledgered, all or nothing, the pivot left alone, the cache flushed only
 * after the commit.
 */
class CapabilityWriterApplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /** @param  array<string, bool>|null  $overrides  stored as the switch endpoint stores them */
    private function org(string $orgType = 'masjid', ?array $overrides = null, ?int $parentId = null): Masjid
    {
        $org = Masjid::create([
            'name' => 'Apply Org ' . uniqid(),
            'email' => 'apply' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
            'assistant_enabled' => false,
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
        ]);

        // Neither column is fillable on purpose (Masjid::hasCapability).
        $org->forceFill(['capability_overrides' => $overrides, 'parent_id' => $parentId])->save();

        return $org->fresh();
    }

    private function actor(): int
    {
        return (int) User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)])->id;
    }

    /** @return array<string, mixed> the stored overrides, as the database holds them */
    private function stored(Masjid $org): array
    {
        return json_decode((string) DB::table('masjids')->where('id', $org->id)->value('capability_overrides'), true) ?? [];
    }

    #[Test]
    public function an_unsent_key_is_never_touched(): void
    {
        // Burlington's shape: its site is run by the owner with `web_pages` off.
        $org = $this->org('masjid', ['web_pages' => false]);
        $actor = $this->actor();

        CapabilityWriter::apply($org, ['events' => false], $actor);

        $this->assertSame(['web_pages' => false, 'events' => false], $this->stored($org));
        $this->assertFalse($org->fresh()->hasCapability('web_pages'));
        $this->assertSame(['events'], MasjidCapabilityChange::pluck('capability')->all(), 'only the key sent is ledgered');
    }

    #[Test]
    public function every_sent_key_stores_an_explicit_override_even_at_its_default(): void
    {
        $org = $this->org('masjid');
        $this->assertTrue($org->hasCapability('events'), 'premise: events is on by default for a masjid');
        $this->assertFalse($org->hasCapability('form_editing'), 'premise: form_editing is off by default');

        CapabilityWriter::apply($org, ['events' => true, 'form_editing' => false], $this->actor());

        // Stored even though each equals its default, so a later change to a
        // catalogue default never moves an organisation a SuperAdmin decided about.
        $this->assertSame(['form_editing' => false, 'events' => true], $this->stored($org));
    }

    #[Test]
    public function each_sent_key_writes_one_ledger_row_no_ops_included(): void
    {
        $org = $this->org('masjid', ['website' => false]);
        $actor = $this->actor();

        // Sent out of catalogue order on purpose.
        $outcome = CapabilityWriter::apply($org, ['events' => false, 'website' => false, 'form_editing' => true], $actor);

        $rows = MasjidCapabilityChange::orderBy('id')->get();

        $this->assertSame(['form_editing', 'website', 'events'], $rows->pluck('capability')->all(), 'one row per key sent, in catalogue order');
        $this->assertSame(['changed' => ['form_editing', 'events'], 'unchanged' => ['website']], $outcome);

        [$grant, $noop, $module] = $rows->all();

        $this->assertEquals([false, true, null], [$grant->enabled_before, $grant->enabled_after, $grant->override_before]);
        $this->assertEquals([false, false, false], [$noop->enabled_before, $noop->enabled_after, $noop->override_before], 'the no-op names the decision it confirmed');
        $this->assertEquals([true, false, null], [$module->enabled_before, $module->enabled_after, $module->override_before]);

        foreach ($rows as $row) {
            $this->assertSame((int) $org->id, $row->masjid_id);
            $this->assertSame($actor, $row->actor_user_id);
        }

        $this->assertSame($actor, (int) $org->fresh()->updated_by);
    }

    #[Test]
    public function giving_off_with_a_live_subscription_refuses_the_whole_request_and_writes_nothing(): void
    {
        $org = $this->org('masjid', ['web_pages' => false]);
        $fund = Fund::factory()->create(['masjid_id' => $org->id]);
        DonationSubscription::create([
            'masjid_id' => $org->id, 'fund_id' => $fund->id,
            'intended_amount' => 5000, 'charged_amount' => 5000, 'currency' => 'usd',
            'interval' => 'month', 'status' => 'active', 'stripe_subscription_id' => 'sub_TEST' . uniqid(),
            'idempotency_key' => 'sub_' . uniqid('', true),
        ]);
        $before = $org->fresh()->getAttributes();

        try {
            CapabilityWriter::apply($org, ['events' => false, 'giving' => false, 'form_editing' => true], $this->actor());
            $this->fail('Giving was switched off while a monthly gift could still bill');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['capability' => ['1 monthly gift can still charge donors. Cancel them on Recurring Donations or in Stripe first.']],
                $e->errors()
            );
        }

        $this->assertSame($before, $org->fresh()->getAttributes(), 'no key was written, not even the ones Giving did not hold');
        $this->assertSame(0, MasjidCapabilityChange::count());
    }

    #[Test]
    public function a_column_backed_or_dotted_key_is_refused(): void
    {
        $org = $this->org('masjid');
        $actor = $this->actor();
        $before = $org->fresh()->getAttributes();

        $refusals = [
            'crm' => 'This capability has its own switch on this screen.',
            'assistant' => 'This capability has its own switch on this screen.',
            // Names a nested config array (config('capabilities.giving.defaults')).
            'giving.defaults' => 'There is no such capability.',
            'nope' => 'There is no such capability.',
        ];

        foreach ($refusals as $key => $sentence) {
            try {
                CapabilityWriter::apply($org, ['events' => false, $key => true], $actor);
                $this->fail("'{$key}' was written");
            } catch (ValidationException $e) {
                $this->assertSame(['capability' => [$sentence]], $e->errors(), $key);
            }
        }

        $this->assertSame($before, $org->fresh()->getAttributes());
        $this->assertSame(0, MasjidCapabilityChange::count());

        // The single switch had the dotted hole: it stored a junk override. Now a 422.
        Sanctum::actingAs(User::findOrFail($actor));
        $this->patch("/api/admin/masjids/{$org->id}/capabilities/giving.defaults", ['enabled' => '1'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => ['There is no such capability.']]]);

        $this->assertSame([], $this->stored($org));
        $this->assertSame(0, MasjidCapabilityChange::count());
    }

    #[Test]
    public function a_value_that_is_not_a_real_boolean_is_a_programming_error_and_writes_nothing(): void
    {
        $org = $this->org('masjid');

        $this->expectException(\InvalidArgumentException::class);

        try {
            CapabilityWriter::apply($org, ['events' => '0'], $this->actor());
        } finally {
            $this->assertSame([], $this->stored($org));
        }
    }

    #[Test]
    public function the_pivot_is_never_touched(): void
    {
        $org = $this->org('masjid');
        $feature = MobileAppFeature::create(['name' => 'Pivot probe', 'key' => 'pivot_probe_' . uniqid(), 'is_available' => true]);
        MasjidMobileAppFeature::create(['masjid_id' => $org->id, 'feature_id' => $feature->id, 'is_available' => false]);
        $pivot = fn () => DB::table('masjid_mobile_app_features')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $before = $pivot();

        CapabilityWriter::apply($org, ['announcements' => false, 'events' => true, 'donation_link' => false], $this->actor());

        $this->assertNotEmpty($before);
        $this->assertSame($before, $pivot(), 'S2b owns the pivot; a live-org write changes installed apps\' drawers only through it');
    }

    #[Test]
    public function two_concurrent_writes_both_land(): void
    {
        $org = $this->org('masjid', ['web_pages' => false]);
        $actor = $this->actor();

        // Both loaded before either wrote: each would overwrite the other's JSON
        // if the writer trusted the model it was handed.
        $first = Masjid::findOrFail($org->id);
        $second = Masjid::findOrFail($org->id);

        CapabilityWriter::apply($first, ['events' => false], $actor);
        CapabilityWriter::apply($second, ['website' => false], $actor);

        $this->assertSame(['web_pages' => false, 'events' => false, 'website' => false], $this->stored($org));
    }

    #[Test]
    public function the_family_cache_is_flushed_after_commit_only(): void
    {
        $parent = $this->org('masjid');
        $org = $this->org('community', null, (int) $parent->id);
        $own = MobileCache::masjidKey((int) $org->id, MobileCache::MENU);
        $parents = MobileCache::masjidKey((int) $parent->id, MobileCache::MENU);
        $actor = $this->actor();

        Cache::put($own, 'stale');
        Cache::put($parents, 'stale');

        DB::transaction(function () use ($org, $own, $parents, $actor) {
            CapabilityWriter::apply($org, ['events' => false], $actor);

            $this->assertTrue(Cache::has($own), 'flushed before the write committed');
            $this->assertTrue(Cache::has($parents), 'flushed before the write committed');
        });

        $this->assertFalse(Cache::has($own));
        $this->assertFalse(Cache::has($parents), "a child's switches build a profile inside its parent's /menu");

        // A write that rolls back never flushes, and leaves no row.
        Cache::put($own, 'stale');

        try {
            DB::transaction(function () use ($org, $actor) {
                CapabilityWriter::apply($org, ['website' => false], $actor);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertTrue(Cache::has($own));
        $this->assertSame(['events' => false], $this->stored($org));
        $this->assertSame(['events'], MasjidCapabilityChange::pluck('capability')->all());
    }
}
