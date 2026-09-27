<?php

namespace Tests\Feature;

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
 * PATCH /api/admin/masjids/{id}/capabilities: several catalogue switches in
 * one request, for Studio opening a live organisation (Studio W2 S7). The same
 * writer as the single switch, CapabilityWriter::apply(), with its 403 and its
 * 422 envelope.
 */
class BulkCapabilitiesEndpointTest extends TestCase
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

    private function org(): Masjid
    {
        return Masjid::create([
            'name' => 'Bulk Org ' . uniqid(),
            'email' => 'bulk' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
            'assistant_enabled' => false,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)])->fresh();
    }

    private function admin(Masjid $masjid): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        return $user->fresh();
    }

    /** Form-encoded, as the SPA's axios default sends it. */
    private function bulk(Masjid $masjid, array $capabilities)
    {
        return $this->patch("/api/admin/masjids/{$masjid->id}/capabilities", ['capabilities' => $capabilities], ['Accept' => 'application/json']);
    }

    /** @return array<string, mixed> */
    private function stored(Masjid $org): array
    {
        return json_decode((string) DB::table('masjids')->where('id', $org->id)->value('capability_overrides'), true) ?? [];
    }

    #[Test]
    public function only_a_super_admin_may_call_it(): void
    {
        $masjid = $this->org();

        $this->bulk($masjid, ['events' => '0'])->assertUnauthorized();

        Sanctum::actingAs($this->admin($masjid));
        $this->bulk($masjid, ['events' => '0'])
            ->assertForbidden()
            ->assertJsonPath('status', 'error');

        $this->assertSame([], $this->stored($masjid));
        $this->assertSame(0, MasjidCapabilityChange::count());

        Sanctum::actingAs($this->superAdmin());
        $this->bulk($masjid, ['events' => '0'])->assertOk();
        $this->patch('/api/admin/masjids/999999/capabilities', ['capabilities' => ['events' => '0']], ['Accept' => 'application/json'])
            ->assertNotFound();
    }

    #[Test]
    public function form_encoded_strings_true_and_false_are_read_as_booleans(): void
    {
        $masjid = $this->org();
        Sanctum::actingAs($this->superAdmin());

        // Every string the browser can send: "1"/"0" from the SPA, and
        // "true"/"false" from a cached bundle (.claude/rules/shipping.md).
        $this->bulk($masjid, ['events' => 'false', 'website' => 'true', 'form_editing' => '1', 'gallery' => '0'])->assertOk();

        $this->assertSame(['form_editing' => true, 'website' => true, 'events' => false, 'gallery' => false], $this->stored($masjid));

        // JSON with real booleans is the same request.
        $this->patchJson("/api/admin/masjids/{$masjid->id}/capabilities", ['capabilities' => ['gallery' => true]])->assertOk();
        $this->assertTrue($this->stored($masjid)['gallery']);

        // Nonsense is refused, never read as false, and writes nothing.
        $count = MasjidCapabilityChange::count();
        $this->bulk($masjid, ['events' => 'maybe', 'gallery' => '0'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['capabilities.events']]);
        $this->assertTrue($this->stored($masjid)['gallery']);
        $this->assertSame($count, MasjidCapabilityChange::count());
    }

    #[Test]
    public function a_null_or_empty_value_is_refused_never_read_as_off(): void
    {
        $masjid = $this->org();
        Sanctum::actingAs($this->superAdmin());

        // JSON null.
        $this->patchJson("/api/admin/masjids/{$masjid->id}/capabilities", ['capabilities' => ['events' => null]])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['capabilities.events']]);

        // A form `capabilities[events]=`, which ConvertEmptyStringsToNull makes null.
        $this->bulk($masjid, ['events' => ''])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['capabilities.events']]);

        // An array is not a boolean either.
        $this->bulk($masjid, ['events' => ['0']])->assertStatus(422);

        $this->assertSame([], $this->stored($masjid), 'no override was stored');
        $this->assertSame(0, MasjidCapabilityChange::count(), 'no ledger row was written');
    }

    #[Test]
    public function an_empty_request_or_a_refused_key_writes_nothing(): void
    {
        $masjid = $this->org();
        Sanctum::actingAs($this->superAdmin());

        $this->bulk($masjid, [])->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->patch("/api/admin/masjids/{$masjid->id}/capabilities", [], ['Accept' => 'application/json'])->assertStatus(422);

        // One refused key refuses the request, in the single switch's envelope.
        $this->bulk($masjid, ['events' => '0', 'crm' => '1'])
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => ['This capability has its own switch on this screen.']]]);
        $this->bulk($masjid, ['events' => '0', 'giving.defaults' => '1'])
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => ['There is no such capability.']]]);

        $this->assertSame([], $this->stored($masjid));
        $this->assertSame(0, MasjidCapabilityChange::count());
    }

    #[Test]
    public function the_response_is_the_single_switchs_payload_plus_what_changed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00', 'UTC'));

        try {
            $masjid = $this->org();
            Sanctum::actingAs($this->superAdmin());

            $bulk = $this->bulk($masjid, ['events' => '0', 'website' => '1'])->assertOk();

            $this->assertSame('success', $bulk->json('status'));
            $this->assertSame(['changed' => ['events'], 'unchanged' => ['website']], $bulk->json('meta'));

            // The same state through the single switch (a no-op now, and time is
            // frozen), so its `data` must be the bulk answer's `data` exactly.
            $single = $this->patch("/api/admin/masjids/{$masjid->id}/capabilities/events", ['enabled' => '0'], ['Accept' => 'application/json'])
                ->assertOk();

            $this->assertSame(
                json_encode($single->json('data')),
                json_encode($bulk->json('data')),
            );
            $this->assertSame(['status', 'data', 'meta'], array_keys($bulk->json()));
            $this->assertSame(['status', 'data'], array_keys($single->json()));
            $this->assertSame(
                json_encode($masjid->fresh()->append(Masjid::ADMIN_APPENDS)->toArray()),
                json_encode($bulk->json('data')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }
}
