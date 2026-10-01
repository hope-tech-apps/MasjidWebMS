<?php

namespace Tests\Feature;

use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The single switch (PATCH .../capabilities/{key}) answers exactly as it did
 * before it started delegating to CapabilityWriter::apply() (Studio W2 S7).
 *
 * The recording was made against the controller that wrote the override
 * itself, and pins, for a run of flips a SuperAdmin makes on the live panel:
 * the status, the response body byte for byte, the stored overrides as the
 * database holds them, and every ledger row. Time is frozen and every row this
 * reads back has a fixed id, so nothing in the recording is incidental.
 *
 * A dotted key (`giving.defaults`) is left out on purpose: it used to store a
 * junk override and now answers 422, which is the one intended change
 * (CapabilityWriterApplyTest::a_column_backed_or_dotted_key_is_refused).
 *
 * To record (only ever against code whose behaviour is the one to keep):
 * SET_CAPABILITY_RECORD=1. Recording never passes, so a recorded run cannot be
 * mistaken for a checked one.
 */
class SetCapabilityDelegatesTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'tests/fixtures/set-capability-responses.json';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_single_switch_answers_byte_for_byte_as_before(): void
    {
        $masjid = Masjid::forceCreate([
            'id' => 9001,
            'name' => 'Delegation Masjid',
            'email' => 'delegation@delegation.test',
            'phone' => '+15550009001',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
            'assistant_enabled' => false,
            'stripe_account_id' => 'acct_TESTdelegation',
            'stripe_charges_enabled' => true,
        ]);
        $super = User::factory()->create(['id' => 9101, 'type' => 'SuperAdmin', 'phone' => '+15550009101']);
        $admin = User::factory()->create(['id' => 9102, 'type' => 'MasjidAdmin', 'phone' => '+15550009102']);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);

        // A monthly gift Stripe can still bill, seeded before any request (a
        // SuperAdmin request binds the tenant, and a later create is stamped).
        $fund = Fund::factory()->create(['masjid_id' => $masjid->id]);
        $gift = DonationSubscription::create([
            'masjid_id' => $masjid->id, 'fund_id' => $fund->id,
            'intended_amount' => 5000, 'charged_amount' => 5000, 'currency' => 'usd',
            'interval' => 'month', 'status' => 'active', 'stripe_subscription_id' => 'sub_TESTdelegation',
            'idempotency_key' => 'sub_delegation',
        ]);

        $steps = [];
        $step = function (string $label, User $as, string $key, array $body) use (&$steps, $masjid) {
            Sanctum::actingAs($as->fresh());

            // Form-encoded, exactly as OrganisationSwitchesPanel sends it.
            $response = $this->patch("/api/admin/masjids/{$masjid->id}/capabilities/{$key}", $body, ['Accept' => 'application/json']);

            $steps[] = [
                'step' => $label,
                'status' => $response->getStatusCode(),
                'body' => $response->getContent(),
                'capability_overrides' => DB::table('masjids')->where('id', $masjid->id)->value('capability_overrides'),
                'updated_by' => DB::table('masjids')->where('id', $masjid->id)->value('updated_by'),
                'ledger' => MasjidCapabilityChange::orderBy('id')->get()
                    ->map(fn (MasjidCapabilityChange $row) => collect($row->getAttributes())->except('id')->all())
                    ->all(),
            ];
        };

        $step('a module off', $super, 'events', ['enabled' => '0']);
        $step('the same flip again, a no-op', $super, 'events', ['enabled' => '0']);
        $step('a grant on', $super, 'form_editing', ['enabled' => '1']);
        $step('a module back on', $super, 'events', ['enabled' => '1']);
        $step('giving off while a gift can bill', $super, 'giving', ['enabled' => '0']);
        $step('giving on, never refused', $super, 'giving', ['enabled' => '1']);
        $step('an unknown key', $super, 'nope', ['enabled' => '1']);
        $step('a column-backed key', $super, 'crm', ['enabled' => '1']);
        $step('a missing value', $super, 'events', []);
        $step('the strings true and false are refused', $super, 'events', ['enabled' => 'false']);
        $step('a masjid admin', $admin, 'events', ['enabled' => '0']);

        // The gift is what made the Giving refusal, and it is untouched.
        $this->assertSame(1, DonationSubscription::withoutGlobalScopes()->whereKey($gift->id)->count());

        $actual = json_encode($steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $path = base_path(self::FIXTURE);

        if (getenv('SET_CAPABILITY_RECORD') === '1') {
            file_put_contents($path, $actual);
            $this->fail("Recorded {$path}. A recording run never passes: run again without SET_CAPABILITY_RECORD.");
        }

        $this->assertFileExists($path, 'No recording of the single switch.');
        $this->assertSame(file_get_contents($path), $actual, 'The single switch no longer answers or writes what was recorded.');
    }

    /**
     * Outside the recording: the harness that made it sent this step to the
     * existing organisation, so that entry was dropped from the fixture rather
     * than kept as a record of something it did not test. The organisation is
     * looked up after the key check and before anything is written, as before.
     */
    #[Test]
    public function an_organisation_that_does_not_exist_is_a_404_that_writes_nothing(): void
    {
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550009103'])->fresh());

        $this->patch('/api/admin/masjids/999999/capabilities/events', ['enabled' => '0'], ['Accept' => 'application/json'])
            ->assertNotFound()
            ->assertJsonPath('status', 'error');

        // The key is checked first: an unknown key on a missing organisation is the key's 422.
        $this->patch('/api/admin/masjids/999999/capabilities/nope', ['enabled' => '0'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => ['There is no such capability.']]]);

        $this->assertSame(0, MasjidCapabilityChange::count());
    }
}
