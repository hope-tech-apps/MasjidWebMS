<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The triage QUEUE itself — what the operator screen asks of
 * GET /api/admin/masjids/{masjid_id}/appointment-requests (T-021 follow-on).
 *
 * AppointmentRequestCrudTest already pins auth, tenancy and the write paths.
 * This suite pins the three things the list view added and the one thing it
 * took away:
 *
 *   - SEARCH is over the UNENCRYPTED contact columns only. The test that a
 *     reason cannot be searched is the important one: `reason` is ciphertext,
 *     so a LIKE against it silently matches nothing, and the only way to
 *     "fix" that is to stop encrypting it. Pinning the behaviour makes that a
 *     failing test rather than a plausible-looking patch.
 *   - SORT is by submitted-at in either direction, ties broken by id so a page
 *     boundary cannot repeat or drop a row.
 *   - STATUS COUNTS describe the whole queue, not the filtered page.
 *   - The LIST PAYLOAD NO LONGER CARRIES date_of_birth OR reason. A queue is
 *     rendered fifteen people at a time on a front-desk screen; the
 *     health-adjacent fields belong to the one-person `show`. Removing them
 *     from the SELECT means the ciphertext is never fetched at all.
 *
 * Every assertion below stays inside one tenant's queue, because a search or a
 * sort is a query path like any other and .claude/rules/tenant-scoping.md wants
 * each one proven, not assumed.
 */
class AppointmentRequestQueueTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
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

        // The endpoints reuse the CONTACTS permissions (routes/admin.php), so
        // the bridged masjid-admin role must exist before the admins do.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            // The route group is gated by masjids.crm_enabled (CrmFeatureGateTest).
            'crm_enabled' => true,
        ], $overrides));
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

    private function url(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id . '/appointment-requests';
    }

    /**
     * Seed one request. Created UNBOUND (no request in flight), so the explicit
     * masjid_id is honored rather than overridden by the creating hook.
     */
    private function seedRequest(Masjid $masjid, array $attributes = []): AppointmentRequest
    {
        return AppointmentRequest::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
        ], $attributes));
    }

    /**
     * Pin created_at at the DB level rather than through the model: created_at
     * is not fillable and an ordinary save() would stamp "now" over it. The
     * queue's whole ordering contract is this column, so the test has to be
     * able to state it exactly.
     */
    private function submittedAt(AppointmentRequest $request, string $timestamp): void
    {
        DB::table('appointment_requests')
            ->where('id', $request->id)
            ->update(['created_at' => $timestamp]);
    }

    // ---------- search ----------

    #[Test]
    public function search_matches_the_applicant_name(): void
    {
        $this->seedRequest($this->masjidA, ['applicant_name' => 'Amal Yusuf']);
        $this->seedRequest($this->masjidA, ['applicant_name' => 'Bilal Rahman']);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url() . '?search=amal')->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('Amal Yusuf', $response->json('data.data.0.applicant_name'));
    }

    #[Test]
    public function search_matches_the_phone_and_the_email(): void
    {
        $this->seedRequest($this->masjidA, [
            'applicant_name' => 'Amal Yusuf',
            'phone' => '+15551234567',
            'email' => 'amal@example.test',
        ]);
        $this->seedRequest($this->masjidA, [
            'applicant_name' => 'Bilal Rahman',
            'phone' => '+15559999999',
            'email' => 'bilal@example.test',
        ]);

        Sanctum::actingAs($this->adminA);

        // The number a receptionist actually has in front of them: a fragment.
        $this->assertSame(1, $this->getJson($this->url() . '?search=5551234')->assertOk()->json('data.total'));
        $this->assertSame(1, $this->getJson($this->url() . '?search=bilal@example')->assertOk()->json('data.total'));
    }

    #[Test]
    public function search_never_matches_the_encrypted_reason(): void
    {
        // `reason` is an `encrypted` cast: the column holds ciphertext, so a
        // LIKE can only ever miss. If this test starts failing, someone has
        // stopped encrypting a health-adjacent field — see
        // .claude/rules/appointments.md and AppointmentRequestEncryptionTest.
        $this->seedRequest($this->masjidA, [
            'applicant_name' => 'Amal Yusuf',
            'reason' => 'persistent migraine',
        ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url() . '?search=migraine')->assertOk();

        $this->assertSame(0, $response->json('data.total'));
    }

    #[Test]
    public function search_stays_inside_the_callers_own_tenant(): void
    {
        $this->seedRequest($this->masjidA, ['applicant_name' => 'Amal Yusuf']);
        // Same person's name in another organization's queue.
        $this->seedRequest($this->masjidB, ['applicant_name' => 'Amal Yusuf']);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url() . '?search=Amal')->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    #[Test]
    public function search_cannot_escape_an_active_status_filter(): void
    {
        // The OR set is grouped; ungrouped it would return the contacted row too.
        $this->seedRequest($this->masjidA, [
            'applicant_name' => 'Amal Yusuf',
            'status' => AppointmentRequest::STATUS_NEW,
        ]);
        $this->seedRequest($this->masjidA, [
            'applicant_name' => 'Amal Rahman',
            'status' => AppointmentRequest::STATUS_CONTACTED,
        ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson(
            $this->url() . '?search=Amal&status=' . AppointmentRequest::STATUS_NEW
        )->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('Amal Yusuf', $response->json('data.data.0.applicant_name'));
    }

    #[Test]
    public function a_wildcard_in_the_search_term_is_matched_literally(): void
    {
        $this->seedRequest($this->masjidA, ['applicant_name' => 'Amal Yusuf']);

        Sanctum::actingAs($this->adminA);

        // Unescaped, '%' would match every row in the tenant's queue.
        $response = $this->getJson($this->url() . '?search=%')->assertOk();

        $this->assertSame(0, $response->json('data.total'));
    }

    #[Test]
    public function an_array_shaped_filter_degrades_instead_of_500ing(): void
    {
        // `?search[]=x` arrives as an array. Cast to string it is a PHP warning
        // (an ErrorException under Laravel's handler); bound into `where` it is
        // a database error. Either way a malformed FILTER would take the whole
        // triage queue down, which is not something a read parameter may do.
        $this->seedRequest($this->masjidA, ['applicant_name' => 'Amal Yusuf']);

        Sanctum::actingAs($this->adminA);

        $this->assertSame(1, $this->getJson($this->url() . '?search[]=Amal')->assertOk()->json('data.total'));
        $this->assertSame(1, $this->getJson($this->url() . '?status[]=new')->assertOk()->json('data.total'));
        $this->assertSame(1, $this->getJson($this->url() . '?sort[]=oldest')->assertOk()->json('data.total'));
    }

    // ---------- sort ----------

    #[Test]
    public function the_queue_is_newest_first_by_default(): void
    {
        $older = $this->seedRequest($this->masjidA, ['applicant_name' => 'Older']);
        $newer = $this->seedRequest($this->masjidA, ['applicant_name' => 'Newer']);
        $this->submittedAt($older, '2026-01-01 09:00:00');
        $this->submittedAt($newer, '2026-06-01 09:00:00');

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url())->assertOk();

        $this->assertSame('Newer', $response->json('data.data.0.applicant_name'));
        $this->assertSame('Older', $response->json('data.data.1.applicant_name'));
    }

    #[Test]
    public function the_queue_can_be_read_oldest_first(): void
    {
        // What a clinic working a backlog actually wants: whoever has been
        // waiting longest, first.
        $older = $this->seedRequest($this->masjidA, ['applicant_name' => 'Older']);
        $newer = $this->seedRequest($this->masjidA, ['applicant_name' => 'Newer']);
        $this->submittedAt($older, '2026-01-01 09:00:00');
        $this->submittedAt($newer, '2026-06-01 09:00:00');

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url() . '?sort=oldest')->assertOk();

        $this->assertSame('Older', $response->json('data.data.0.applicant_name'));
        $this->assertSame('Newer', $response->json('data.data.1.applicant_name'));
    }

    #[Test]
    public function an_unrecognized_sort_falls_back_to_newest_first(): void
    {
        // A filter is a read, not a write: it degrades, it does not 422.
        $older = $this->seedRequest($this->masjidA, ['applicant_name' => 'Older']);
        $newer = $this->seedRequest($this->masjidA, ['applicant_name' => 'Newer']);
        $this->submittedAt($older, '2026-01-01 09:00:00');
        $this->submittedAt($newer, '2026-06-01 09:00:00');

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url() . '?sort=sideways')->assertOk();

        $this->assertSame('Newer', $response->json('data.data.0.applicant_name'));
    }

    // ---------- payload shape ----------

    #[Test]
    public function the_queue_payload_omits_the_encrypted_fields(): void
    {
        $this->seedRequest($this->masjidA, [
            'applicant_name' => 'Amal Yusuf',
            'reason' => 'persistent migraine',
            'date_of_birth' => '1990-04-02',
        ]);

        Sanctum::actingAs($this->adminA);

        $row = $this->getJson($this->url())->assertOk()->json('data.data.0');

        // Not merely hidden from the template — never selected at all.
        $this->assertArrayNotHasKey('reason', $row);
        $this->assertArrayNotHasKey('date_of_birth', $row);
        // Nor the abuse-investigation metadata, which triage has no use for.
        $this->assertArrayNotHasKey('ip_address', $row);
        $this->assertArrayNotHasKey('user_agent', $row);

        // What the queue DOES need to be workable.
        $this->assertSame('Amal Yusuf', $row['applicant_name']);
        $this->assertArrayHasKey('phone', $row);
        $this->assertArrayHasKey('status', $row);
        $this->assertArrayHasKey('created_at', $row);
        $this->assertSame(0, $row['notes_count']);
    }

    #[Test]
    public function the_detail_endpoint_still_serves_the_encrypted_fields(): void
    {
        // The counterpart guard: narrowing the LIST must not narrow the one
        // screen a clinician actually reads.
        $request = $this->seedRequest($this->masjidA, [
            'reason' => 'persistent migraine',
            'date_of_birth' => '1990-04-02',
        ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url() . '/' . $request->id)->assertOk();

        $this->assertSame('persistent migraine', $response->json('data.reason'));
        $this->assertSame('1990-04-02', $response->json('data.date_of_birth'));
    }

    // ---------- meta ----------

    #[Test]
    public function status_counts_describe_the_whole_queue_not_the_filtered_page(): void
    {
        $this->seedRequest($this->masjidA, ['status' => AppointmentRequest::STATUS_NEW]);
        $this->seedRequest($this->masjidA, ['status' => AppointmentRequest::STATUS_NEW]);
        $this->seedRequest($this->masjidA, ['status' => AppointmentRequest::STATUS_CONTACTED]);
        // Another organization's queue must not reach these numbers.
        $this->seedRequest($this->masjidB, ['status' => AppointmentRequest::STATUS_NEW]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url() . '?status=' . AppointmentRequest::STATUS_CONTACTED)
            ->assertOk();

        // The page is filtered...
        $this->assertSame(1, $response->json('data.total'));
        // ...the tab labels are not.
        $this->assertSame(2, $response->json('meta.status_counts.' . AppointmentRequest::STATUS_NEW));
        $this->assertSame(1, $response->json('meta.status_counts.' . AppointmentRequest::STATUS_CONTACTED));
        // Every status in the vocabulary is present, so a tab can render 0.
        $this->assertSame(0, $response->json('meta.status_counts.' . AppointmentRequest::STATUS_SCHEDULED));
        $this->assertSame(0, $response->json('meta.status_counts.' . AppointmentRequest::STATUS_CLOSED));
    }

    // ---------- pagination ----------

    #[Test]
    public function per_page_is_clamped(): void
    {
        AppointmentRequest::factory()->count(3)->create(['masjid_id' => $this->masjidA->id]);

        Sanctum::actingAs($this->adminA);

        // An honest small page is honored...
        $this->assertSame(2, $this->getJson($this->url() . '?per_page=2')->assertOk()->json('data.per_page'));

        // ...and "give me everything" is refused: this endpoint holds patient
        // records, so one request must not be able to drain the table.
        $this->assertSame(
            100,
            $this->getJson($this->url() . '?per_page=100000')->assertOk()->json('data.per_page')
        );

        // A nonsense page size falls back to the default rather than 0, which
        // Laravel would otherwise treat as "no limit".
        $this->assertSame(15, $this->getJson($this->url() . '?per_page=abc')->assertOk()->json('data.per_page'));
    }
}
