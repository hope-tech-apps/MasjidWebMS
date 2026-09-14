<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\CapabilityLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Every SuperAdmin switch flip on an organisation leaves a row
 * (masjid_capability_changes, DECISIONS.md 2026-09-16): the catalogue toggle,
 * the CRM and Assistant switches and the directory listing, no-ops included.
 * A refused caller writes nothing, and a row cannot be rewritten.
 */
class CapabilityChangeLedgerTest extends TestCase
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

    private function org(string $orgType = 'masjid', bool $crm = true): Masjid
    {
        return Masjid::create([
            'name' => 'Ledger Org ' . uniqid(),
            'email' => 'ledger' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => $crm, 'org_type' => $orgType,
            'assistant_enabled' => false,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh();
    }

    private function admin(Masjid $masjid): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        return $user->fresh();
    }

    private function flip(Masjid $masjid, string $path, array $body)
    {
        // Form-encoded, exactly as the SPA sends it.
        return $this->patch("/api/admin/masjids/{$masjid->id}/{$path}", $body, ['Accept' => 'application/json']);
    }

    #[Test]
    public function a_catalogue_flip_records_before_after_the_prior_decision_and_who(): void
    {
        $masjid = $this->org('school');
        $super = $this->superAdmin();
        Sanctum::actingAs($super);

        $this->flip($masjid, 'capabilities/events', ['enabled' => '0'])->assertOk();

        $row = MasjidCapabilityChange::sole();
        $this->assertSame((int) $masjid->id, $row->masjid_id);
        $this->assertSame('events', $row->capability);
        $this->assertTrue($row->enabled_before);
        $this->assertFalse($row->enabled_after);
        $this->assertNull($row->override_before, 'no SuperAdmin decision existed yet; the default applied');
        $this->assertSame((int) $super->id, $row->actor_user_id);
        $this->assertNotNull($row->created_at);

        // A no-op is still a row, and it names the decision it confirmed.
        $this->flip($masjid, 'capabilities/events', ['enabled' => '0'])->assertOk();

        $again = MasjidCapabilityChange::orderByDesc('id')->first();
        $this->assertSame(2, MasjidCapabilityChange::count());
        $this->assertFalse($again->enabled_before);
        $this->assertFalse($again->enabled_after);
        $this->assertFalse($again->override_before);

        // A grant, the same way.
        $this->flip($masjid, 'capabilities/form_editing', ['enabled' => '1'])->assertOk();
        $grant = MasjidCapabilityChange::orderByDesc('id')->first();
        $this->assertSame('form_editing', $grant->capability);
        $this->assertFalse($grant->enabled_before);
        $this->assertTrue($grant->enabled_after);
    }

    #[Test]
    public function the_crm_assistant_and_directory_switches_are_recorded_too(): void
    {
        $masjid = $this->org('masjid', crm: false);
        Sanctum::actingAs($this->superAdmin());

        $this->flip($masjid, 'crm-access', ['enabled' => '1'])->assertOk();
        $this->flip($masjid, 'assistant-access', ['enabled' => '1'])->assertOk();
        $this->flip($masjid, 'directory-listing', ['listed' => '1'])->assertOk();
        $this->flip($masjid, 'directory-listing', ['listed' => '1'])->assertOk();

        $rows = MasjidCapabilityChange::where('masjid_id', $masjid->id)->orderBy('id')->get();

        $this->assertSame(
            ['crm', 'assistant', CapabilityLedger::DIRECTORY_LISTING, CapabilityLedger::DIRECTORY_LISTING],
            $rows->pluck('capability')->all()
        );

        foreach ([0, 1, 2] as $i) {
            $this->assertFalse($rows[$i]->enabled_before, "{$rows[$i]->capability} before");
            $this->assertTrue($rows[$i]->enabled_after, "{$rows[$i]->capability} after");
            // Column-backed and directory switches have no override to remember.
            $this->assertNull($rows[$i]->override_before);
        }

        // The second listing flip changed nothing, and says so.
        $this->assertTrue($rows[3]->enabled_before);
        $this->assertTrue($rows[3]->enabled_after);
    }

    #[Test]
    public function a_refused_or_invalid_flip_writes_nothing(): void
    {
        $masjid = $this->org('masjid');

        Sanctum::actingAs($this->admin($masjid));
        $this->flip($masjid, 'capabilities/events', ['enabled' => '0'])->assertForbidden();
        $this->flip($masjid, 'crm-access', ['enabled' => '0'])->assertForbidden();
        $this->flip($masjid, 'assistant-access', ['enabled' => '1'])->assertForbidden();
        $this->flip($masjid, 'directory-listing', ['listed' => '1'])->assertForbidden();

        Sanctum::actingAs($this->superAdmin());
        $this->flip($masjid, 'capabilities/nope', ['enabled' => '1'])->assertStatus(422);
        $this->flip($masjid, 'capabilities/crm', ['enabled' => '1'])->assertStatus(422);

        $this->assertSame(0, MasjidCapabilityChange::count());
    }

    #[Test]
    public function a_row_cannot_be_changed_or_deleted(): void
    {
        $row = CapabilityLedger::record($this->org('masjid'), 'events', true, false, null, null);

        try {
            $row->update(['enabled_after' => true]);
            $this->fail('a ledger row was updated');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $row->delete();
            $this->fail('a ledger row was deleted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertFalse(MasjidCapabilityChange::findOrFail($row->id)->enabled_after);
    }

    #[Test]
    public function every_flip_is_logged_at_the_deployed_level(): void
    {
        Log::spy();

        $masjid = $this->org('school');
        Sanctum::actingAs($this->superAdmin());

        $this->flip($masjid, 'capabilities/website', ['enabled' => '0'])->assertOk();

        // Production runs LOG_LEVEL=warning; an info line would not exist there.
        Log::shouldHaveReceived('warning')
            ->with('Organisation capability changed', Mockery::on(fn ($context) => is_array($context)
                && $context['masjid_id'] === (int) $masjid->id
                && $context['capability'] === 'website'
                && $context['enabled_before'] === true
                && $context['enabled_after'] === false))
            ->once();
    }

    #[Test]
    public function the_table_has_no_updated_at_and_a_hand_named_index(): void
    {
        $this->assertTrue(Schema::hasColumns('masjid_capability_changes', [
            'id', 'masjid_id', 'capability', 'enabled_before', 'enabled_after',
            'override_before', 'actor_user_id', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('masjid_capability_changes', 'updated_at'));

        $names = collect(Schema::getIndexes('masjid_capability_changes'))->pluck('name')->all();
        $this->assertContains('mcc_masjid_created_idx', $names);
    }
}
