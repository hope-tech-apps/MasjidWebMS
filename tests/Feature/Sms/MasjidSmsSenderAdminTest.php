<?php

namespace Tests\Feature\Sms;

use App\Models\Masjid;
use App\Models\MasjidSmsSender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MasjidSmsSenderController — the endpoint that sets a tenant's 10DLC sender
 * identity, which had no test of any kind.
 *
 * What makes this small endpoint dangerous is the blast radius of getting the
 * TENANT wrong. The row it writes decides which number an organisation's
 * messages originate from, and the inbound STOP webhook resolves the tenant by
 * matching that number back. Writing masjid A's approved number onto masjid B
 * would therefore do two things at once: put B's traffic on A's registered
 * number (the exact behaviour that gets a whole provider account suspended,
 * .claude/rules/broadcasts.md), and send B's opt-outs to A. So the claim under
 * test is that the masjid is derived from the ROUTE and the body's `masjid_id`
 * is inert — and that a refused request writes nothing at all.
 *
 * The surface is SuperAdmin-only on purpose (an operator records the outcome of
 * a human registration process), so the access matrix is pinned too. Note the
 * two different refusals, which are not interchangeable: `super` answers a
 * non-super caller with 401, while `tenant` answers a MasjidAdmin who names
 * somebody else's masjid with 403 — and the second fires first.
 *
 * Nothing here calls a provider; the controller performs no registration, it
 * records one. There is consequently no outbound seam to mock.
 */
class MasjidSmsSenderAdminTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private User $super;
    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->super = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $this->adminA = $this->makeAdminFor($this->masjidA);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function senderUrl(Masjid $masjid): string
    {
        return "/api/admin/masjids/{$masjid->id}/sms-sender";
    }

    private function approvedPayload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'twilio',
            'phone_number' => '+16135550142',
            'sender_label' => 'Masjid Al-Noor',
            'registration_status' => MasjidSmsSender::STATUS_APPROVED,
            'brand_registration_id' => 'BN1234',
            'campaign_registration_id' => 'CM5678',
        ], $overrides);
    }

    // =========================================================== access control

    #[Test]
    public function the_endpoint_rejects_unauthenticated_requests(): void
    {
        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload())
            ->assertStatus(401);

        $this->assertSame(0, MasjidSmsSender::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_masjid_admin_cannot_set_their_own_organizations_sender(): void
    {
        // 10DLC registration is performed by the platform on the organisation's
        // behalf. An admin who could self-declare "approved" would be putting
        // unregistered traffic on the carriers in the platform's name.
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload())
            ->assertStatus(401);

        $this->assertSame(0, MasjidSmsSender::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_masjid_admin_naming_another_masjid_is_refused_by_the_tenant_layer(): void
    {
        // 403 rather than 401: ResolveMasjidTenant runs BEFORE `super` and
        // refuses the foreign masjid first. The distinction matters — it is the
        // tenant boundary answering, not the role gate.
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->senderUrl($this->masjidB), $this->approvedPayload())
            ->assertStatus(403);

        $this->getJson($this->senderUrl($this->masjidB))->assertStatus(403);

        $this->assertSame(0, MasjidSmsSender::withoutMasjidScope()->count());
    }

    // ============================================================== the tenancy

    #[Test]
    public function update_creates_the_sender_for_the_masjid_named_by_the_route(): void
    {
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload())
            ->assertOk()
            ->assertJsonPath('data.masjid_id', $this->masjidA->id)
            ->assertJsonPath('data.can_send', true)
            ->assertJsonPath('data.refusal_reason', null);

        $this->assertDatabaseHas('masjid_sms_senders', [
            'masjid_id' => $this->masjidA->id,
            'phone_number' => '+16135550142',
            'registration_status' => MasjidSmsSender::STATUS_APPROVED,
        ]);
    }

    #[Test]
    public function a_client_supplied_masjid_id_in_the_body_is_inert(): void
    {
        // The whole tenancy claim for this endpoint. Writing A's approved number
        // onto B would put B's traffic on A's registered number AND route B's
        // inbound STOPs to A.
        Sanctum::actingAs($this->super);

        $this->putJson(
            $this->senderUrl($this->masjidA),
            $this->approvedPayload(['masjid_id' => $this->masjidB->id])
        )->assertOk()->assertJsonPath('data.masjid_id', $this->masjidA->id);

        $this->assertSame(1, MasjidSmsSender::withoutMasjidScope()->count());
        $this->assertDatabaseHas('masjid_sms_senders', ['masjid_id' => $this->masjidA->id]);
        $this->assertDatabaseMissing('masjid_sms_senders', ['masjid_id' => $this->masjidB->id]);
    }

    #[Test]
    public function each_masjid_keeps_its_own_sender_row(): void
    {
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload([
            'phone_number' => '+16135550142',
        ]))->assertOk();

        $this->putJson($this->senderUrl($this->masjidB), $this->approvedPayload([
            'phone_number' => '+16135550199',
            'sender_label' => 'Masjid Al-Huda',
        ]))->assertOk();

        $this->assertSame(2, MasjidSmsSender::withoutMasjidScope()->count());
        $this->assertSame(
            '+16135550142',
            MasjidSmsSender::withoutMasjidScope()->where('masjid_id', $this->masjidA->id)->value('phone_number')
        );
        $this->assertSame(
            '+16135550199',
            MasjidSmsSender::withoutMasjidScope()->where('masjid_id', $this->masjidB->id)->value('phone_number')
        );
    }

    #[Test]
    public function a_second_update_edits_the_same_row_rather_than_adding_one(): void
    {
        // masjid_id is UNIQUE, so "which number does this organisation send
        // from?" must keep exactly one answer.
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload())->assertOk();
        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload([
            'phone_number' => '+16135550143',
        ]))->assertOk();

        $this->assertSame(1, MasjidSmsSender::withoutMasjidScope()->count());
        $this->assertSame(
            '+16135550143',
            MasjidSmsSender::withoutMasjidScope()->where('masjid_id', $this->masjidA->id)->value('phone_number')
        );
    }

    // ======================================================= the approval stamp

    #[Test]
    public function approving_stamps_the_date_and_leaving_approved_clears_it(): void
    {
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload())->assertOk();

        $sender = MasjidSmsSender::withoutMasjidScope()->firstOrFail();
        $this->assertNotNull($sender->approved_at);

        // The carriers suspend it. "approved" must not keep a stale date, and
        // the sender must stop being able to send.
        $response = $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload([
            'registration_status' => MasjidSmsSender::STATUS_SUSPENDED,
        ]))->assertOk();

        $this->assertNull(MasjidSmsSender::withoutMasjidScope()->firstOrFail()->approved_at);
        $this->assertFalse($response->json('data.can_send'));
        $this->assertNotNull($response->json('data.refusal_reason'));
    }

    #[Test]
    public function re_saving_an_approved_sender_keeps_the_original_approval_date(): void
    {
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload())->assertOk();
        $first = MasjidSmsSender::withoutMasjidScope()->firstOrFail()->approved_at;

        Carbon::setTestNow(Carbon::now()->addDays(3));

        // An unrelated edit (a note) must not re-date the carrier's approval.
        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload([
            'notes' => 'EIN confirmed with the treasurer.',
        ]))->assertOk();

        $this->assertEquals($first, MasjidSmsSender::withoutMasjidScope()->firstOrFail()->approved_at);

        Carbon::setTestNow();
    }

    // ================================================== refusals write nothing

    #[Test]
    public function an_approved_sender_with_nothing_to_send_from_is_refused(): void
    {
        // Approving with neither a number nor a messaging service produces a
        // tenant that passes canSend()'s first half and then fails at the
        // provider on every single message.
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), [
            'registration_status' => MasjidSmsSender::STATUS_APPROVED,
            'sender_label' => 'Masjid Al-Noor',
        ])->assertStatus(422);

        $this->assertSame(0, MasjidSmsSender::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_number_that_cannot_be_normalised_is_refused(): void
    {
        // The inbound STOP webhook matches on this column exactly. A number
        // stored in any other shape would never match, and that organisation's
        // opt-outs would silently go unrecorded.
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload([
            'phone_number' => '555-0142',
        ]))->assertStatus(422);

        $this->assertSame(0, MasjidSmsSender::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_human_typed_number_is_stored_normalised(): void
    {
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload([
            'phone_number' => '(613) 555-0142',
        ]))->assertOk();

        $this->assertDatabaseHas('masjid_sms_senders', [
            'masjid_id' => $this->masjidA->id,
            'phone_number' => '+16135550142',
        ]);
    }

    #[Test]
    public function an_unknown_registration_status_is_refused(): void
    {
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload([
            'registration_status' => 'definitely-approved',
        ]))->assertStatus(422);

        $this->assertSame(0, MasjidSmsSender::withoutMasjidScope()->count());
    }

    // ================================================================== the read

    #[Test]
    public function show_states_the_refusal_for_an_organization_with_no_sender(): void
    {
        Sanctum::actingAs($this->super);

        $this->getJson($this->senderUrl($this->masjidB))
            ->assertOk()
            ->assertJsonPath('data.masjid_id', $this->masjidB->id)
            ->assertJsonPath('data.sender', null)
            ->assertJsonPath('data.can_send', false)
            ->assertJsonPath('data.provider_configured', false);
    }

    #[Test]
    public function show_reads_only_the_routed_masjids_sender(): void
    {
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload())->assertOk();

        // A SuperAdmin acts on any masjid, but only ONE at a time: asking about
        // B must not answer with A's approved number.
        $this->getJson($this->senderUrl($this->masjidB))
            ->assertOk()
            ->assertJsonPath('data.sender', null)
            ->assertJsonPath('data.can_send', false);

        $this->getJson($this->senderUrl($this->masjidA))
            ->assertOk()
            ->assertJsonPath('data.sender.phone_number', '+16135550142')
            ->assertJsonPath('data.can_send', true);
    }

    #[Test]
    public function a_pending_registration_cannot_send(): void
    {
        // "The paperwork is submitted" is not carrier permission.
        Sanctum::actingAs($this->super);

        $this->putJson($this->senderUrl($this->masjidA), $this->approvedPayload([
            'registration_status' => MasjidSmsSender::STATUS_PENDING,
        ]))->assertOk()
            ->assertJsonPath('data.can_send', false);

        $this->assertNull(MasjidSmsSender::withoutMasjidScope()->firstOrFail()->approved_at);
    }
}
