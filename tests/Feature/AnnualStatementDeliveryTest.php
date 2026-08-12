<?php

namespace Tests\Feature;

use App\Mail\AnnualStatementMail;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AnnualStatementsController::send and ::sendAll — the two endpoints that MAIL
 * DONORS, and which had no test of any kind.
 *
 * `sendAll` is the most dangerous button in the admin surface. It is one POST
 * with no id in it, it fans out to EVERY donor with an email address, and what
 * it sends is a tax document naming a person and their giving for a year. There
 * are exactly two ways for it to go wrong and both are unrecoverable once the
 * mail is out: sending to the wrong year, and sending one organization's donors
 * a document computed from another's ledger. So the fan-out is asserted
 * RECIPIENT BY RECIPIENT rather than by a count — a count of 3 is equally
 * consistent with three right donors and with two right ones plus a stranger.
 *
 * Mail::fake intercepts delivery; AnnualStatementMail is a ShouldQueue mailable,
 * so `Mail::to(...)->send(...)` queues it and `assertQueued` is the assertion
 * that sees it (the same shape FormNotificationTest uses).
 *
 * The year under test is pinned explicitly rather than left to the controller's
 * "last calendar year" default, so this suite does not start failing on 1 January.
 */
class AnnualStatementDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2025;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private User $adminA;

    private Fund $fundA;
    private Fund $fundB;

    /** Masjid A's donors. */
    private Contact $amina;      // gave, has an email  -> must receive
    private Contact $khalid;     // gave, NO email      -> must be skipped
    private Contact $sara;       // gave, has an email  -> must receive

    /** Masjid B's donor — must never receive anything from A's admin. */
    private Contact $bilal;

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

        config(['services.stripe.currency' => 'usd']);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        Mail::fake();

        $this->masjidA = $this->makeMasjid('Masjid Al-Noor');
        $this->masjidB = $this->makeMasjid('Masjid Al-Huda');

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->fundA = $this->makeFund($this->masjidA);
        $this->fundB = $this->makeFund($this->masjidB);

        $this->amina = $this->makeDonor($this->masjidA, 'Amina', 'Yusuf', 'amina@example.test');
        $this->khalid = $this->makeDonor($this->masjidA, 'Khalid', 'Omar', null);
        $this->sara = $this->makeDonor($this->masjidA, 'Sara', 'Idris', 'sara@example.test');
        $this->bilal = $this->makeDonor($this->masjidB, 'Bilal', 'Haddad', 'bilal@example.test');

        $this->gift($this->masjidA, $this->fundA, $this->amina, 25000, self::YEAR . '-03-14');
        $this->gift($this->masjidA, $this->fundA, $this->khalid, 10000, self::YEAR . '-05-02');
        $this->gift($this->masjidA, $this->fundA, $this->sara, 50000, self::YEAR . '-11-30');
        $this->gift($this->masjidB, $this->fundB, $this->bilal, 99900, self::YEAR . '-07-07');
    }

    private function makeMasjid(string $name): Masjid
    {
        return Masjid::create([
            'name' => $name . ' ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
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

    private function makeFund(Masjid $masjid): Fund
    {
        return Fund::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'name' => 'General',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);
    }

    private function makeDonor(Masjid $masjid, string $first, string $last, ?string $email): Contact
    {
        // Emails are set explicitly (never the factory's optional fake one): the
        // whole subject here is WHO gets mailed, so an accidental address on a
        // donor meant to be skipped would flip an assertion into a false pass.
        return Contact::factory()->create([
            'masjid_id' => $masjid->id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
        ]);
    }

    private function gift(Masjid $masjid, Fund $fund, Contact $contact, int $cents, string $date): Donation
    {
        return Donation::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'contact_id' => $contact->id,
            'type' => 'one_time',
            'source' => 'offline',
            'payment_method' => 'check',
            'donated_at' => $date,
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'currency' => 'usd',
            'status' => 'succeeded',
            'idempotency_key' => 'gift_' . uniqid(),
        ]);
    }

    private function statementsUrl(Masjid $masjid): string
    {
        return "/api/admin/masjids/{$masjid->id}/annual-statements";
    }

    /** Every address a statement was queued to, in no particular order. */
    private function queuedRecipients(): array
    {
        $addresses = [];

        Mail::assertQueued(AnnualStatementMail::class, function ($mail) use (&$addresses) {
            foreach ($mail->to as $recipient) {
                $addresses[] = $recipient['address'];
            }

            return true;
        });

        return $addresses;
    }

    // ==================================================================== auth

    #[Test]
    public function send_all_rejects_unauthenticated_requests(): void
    {
        $this->postJson($this->statementsUrl($this->masjidA) . '/send-all')
            ->assertStatus(401);

        Mail::assertNothingQueued();
    }

    // ============================================================ send: one donor

    #[Test]
    public function send_queues_one_statement_to_the_named_donor(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->statementsUrl($this->masjidA) . "/{$this->amina->id}/send?year=" . self::YEAR)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        Mail::assertQueued(AnnualStatementMail::class, 1);
        Mail::assertQueued(
            AnnualStatementMail::class,
            fn ($mail) => $mail->hasTo('amina@example.test')
                && $mail->year === self::YEAR
                && $mail->donorName === 'Amina Yusuf'
                && $mail->totalEligible === '250.00'
        );
    }

    #[Test]
    public function send_refuses_a_donor_with_no_email_and_queues_nothing(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->statementsUrl($this->masjidA) . "/{$this->khalid->id}/send?year=" . self::YEAR)
            ->assertStatus(422);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function send_is_a_404_for_a_donor_with_no_giving_that_year(): void
    {
        Sanctum::actingAs($this->adminA);

        $silent = $this->makeDonor($this->masjidA, 'Nour', 'Salim', 'nour@example.test');

        $this->postJson($this->statementsUrl($this->masjidA) . "/{$silent->id}/send?year=" . self::YEAR)
            ->assertStatus(404);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function send_cannot_mail_another_masjids_donor(): void
    {
        Sanctum::actingAs($this->adminA);

        // B's contact id under A's own route. The statement service resolves the
        // contact WITHIN the route masjid, so there is nothing to send — and
        // crucially, Bilal's inbox stays empty.
        $this->postJson($this->statementsUrl($this->masjidA) . "/{$this->bilal->id}/send?year=" . self::YEAR)
            ->assertStatus(404);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function send_in_another_masjids_route_is_a_403(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->statementsUrl($this->masjidB) . "/{$this->bilal->id}/send?year=" . self::YEAR)
            ->assertStatus(403);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function show_does_not_disclose_another_masjids_donor(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->statementsUrl($this->masjidA) . "/{$this->bilal->id}?year=" . self::YEAR)
            ->assertStatus(404);

        $this->assertStringNotContainsString('Bilal', $response->content());
        $this->assertStringNotContainsString('999.00', $response->content());
    }

    // ========================================================== sendAll: fan-out

    #[Test]
    public function send_all_reaches_every_donor_with_an_email_and_skips_the_rest(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->statementsUrl($this->masjidA) . '/send-all?year=' . self::YEAR)
            ->assertOk()
            ->assertJsonPath('data.year', self::YEAR)
            ->assertJsonPath('data.queued', 2)
            ->assertJsonPath('data.skipped', 1);

        // Named, not counted: two right donors is the claim, and a count alone
        // cannot tell that apart from one right donor plus a stranger.
        $this->assertEqualsCanonicalizing(
            ['amina@example.test', 'sara@example.test'],
            $this->queuedRecipients()
        );
    }

    #[Test]
    public function send_all_never_crosses_into_another_organizations_donors(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->statementsUrl($this->masjidA) . '/send-all?year=' . self::YEAR)
            ->assertOk()
            ->assertJsonPath('data.queued', 2);

        // The neighbour's donor is not among the recipients...
        $this->assertNotContains('bilal@example.test', $this->queuedRecipients());

        // ...and no queued statement carries their name or their $999 total,
        // which is what a leak through the SUMMARY query would look like even if
        // the addressing were right.
        Mail::assertQueued(AnnualStatementMail::class, function ($mail) {
            return $mail->donorName !== 'Bilal Haddad' && $mail->totalEligible !== '999.00';
        });
    }

    #[Test]
    public function send_all_in_another_masjids_route_is_a_403_and_mails_nobody(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->statementsUrl($this->masjidB) . '/send-all?year=' . self::YEAR)
            ->assertStatus(403);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function send_all_only_mails_donors_who_gave_in_the_requested_year(): void
    {
        // A donor whose only gift is in a different year must not receive a
        // statement claiming they gave nothing — or worse, one for the wrong year.
        $older = $this->makeDonor($this->masjidA, 'Yusra', 'Kamal', 'yusra@example.test');
        $this->gift($this->masjidA, $this->fundA, $older, 40000, (self::YEAR - 1) . '-06-01');

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->statementsUrl($this->masjidA) . '/send-all?year=' . self::YEAR)
            ->assertOk()
            ->assertJsonPath('data.queued', 2);

        $this->assertNotContains('yusra@example.test', $this->queuedRecipients());
    }

    #[Test]
    public function send_all_ignores_gifts_that_never_settled(): void
    {
        // A pending gift is not giving. Mailing a tax document that counts one
        // overstates a donor's deduction on the organization's letterhead.
        $pendingDonor = $this->makeDonor($this->masjidA, 'Rami', 'Nasser', 'rami@example.test');

        Donation::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidA->id,
            'fund_id' => $this->fundA->id,
            'contact_id' => $pendingDonor->id,
            'type' => 'one_time',
            'source' => 'stripe',
            'donated_at' => self::YEAR . '-04-04',
            'intended_amount' => 80000,
            'charged_amount' => 80000,
            'currency' => 'usd',
            'status' => 'pending',
            'idempotency_key' => 'gift_' . uniqid(),
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->statementsUrl($this->masjidA) . '/send-all?year=' . self::YEAR)
            ->assertOk()
            ->assertJsonPath('data.queued', 2);

        $this->assertNotContains('rami@example.test', $this->queuedRecipients());
    }

    #[Test]
    public function the_summary_report_totals_only_this_organizations_giving(): void
    {
        // The report is what an admin reads before pressing send-all, so it has
        // to agree with the fan-out. $250 + $100 + $500, and none of B's $999.
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->statementsUrl($this->masjidA) . '?year=' . self::YEAR)
            ->assertOk();

        $this->assertSame(85000, $response->json('data.total_eligible'));
        $this->assertCount(3, $response->json('data.donors'));
        $this->assertNotContains(
            'bilal@example.test',
            array_column($response->json('data.donors'), 'email')
        );
    }

    #[Test]
    public function a_super_admin_send_all_stays_inside_the_routed_masjid(): void
    {
        // The operator can act on any organization, so on this endpoint they are
        // one POST away from mailing every donor on the platform. The route is
        // what confines them.
        $super = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        Sanctum::actingAs($super);

        $this->postJson($this->statementsUrl($this->masjidA) . '/send-all?year=' . self::YEAR)
            ->assertOk()
            ->assertJsonPath('data.queued', 2);

        $this->assertEqualsCanonicalizing(
            ['amina@example.test', 'sara@example.test'],
            $this->queuedRecipients()
        );
    }

    #[Test]
    public function send_all_is_behind_the_per_masjid_crm_gate(): void
    {
        // An organization that never enabled the CRM must not be mailable at
        // all — and a 403 here has to arrive before the fan-out, not after it.
        $this->masjidA->forceFill(['crm_enabled' => false])->save();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->statementsUrl($this->masjidA) . '/send-all?year=' . self::YEAR)
            ->assertStatus(403);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_queued_statement_carries_the_letter_pdf(): void
    {
        // The letter is the primary artifact — most donors have no email, and
        // the ones who do are sent the same document the download produces.
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->statementsUrl($this->masjidA) . "/{$this->amina->id}/send?year=" . self::YEAR)
            ->assertOk();

        Mail::assertQueued(AnnualStatementMail::class, function ($mail) {
            return is_string($mail->pdf)
                && str_starts_with($mail->pdf, '%PDF-')
                && $mail->pdfName === '2025-giving-statement-Amina-Yusuf.pdf';
        });
    }
}
