<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /api/v1/appointment-requests as an ABUSE SURFACE (T-043h).
 *
 * AppointmentRequestSubmissionTest already pins that the endpoint works and
 * that the obvious client-supplied fields are ignored. This file pins the
 * properties that make it safe to leave unauthenticated on the open internet —
 * each one is a thing a reasonable future change would take away without
 * anybody noticing, because none of them has a visible symptom when it breaks.
 *
 * ## Why "no mail" is the first test in the file
 *
 * The obvious next feature request on an intake form is "send the applicant a
 * confirmation email". On an UNAUTHENTICATED endpoint that accepts an arbitrary
 * `email`, that feature is an open mail relay wearing a clinic's face:
 *
 *  - the attacker chooses the recipient (anyone, including someone who never
 *    contacted the organisation),
 *  - the attacker chooses the content, because `applicant_name` and `reason`
 *    are free text that a confirmation would quote back,
 *  - and the mail is sent by, signed by and reputationally charged to the
 *    organisation's own domain — the one they also use to reach patients.
 *
 * Eight requests an hour per IP+organisation is a sensible cap on rows in a
 * triage queue. It is not a sensible cap on outbound mail to strangers, and the
 * throttle key is not the right control for it in any case: the limit exists to
 * protect the queue, and it would silently become the only thing standing
 * between the clinic's domain and a blocklist.
 *
 * The decision is therefore: THIS PATH SENDS NOTHING. A person is told to
 * expect a phone call, and staff make it from the admin inbox where the
 * recipient is a row they can see. If a confirmation is ever wanted, it must be
 * sent from the ADMIN side, to a request a human has looked at — an entirely
 * different trust position. The test below is what makes that decision
 * survive a well-meaning follow-up ticket.
 *
 * ## The rest, in one line each
 *
 *  - A soft-deleted organisation and one that never existed answer with the
 *    SAME words: the 404 must not become a directory of who is real.
 *  - Nothing in the request or the response names a staff member.
 *  - The success payload does not hand back the PII the submitter just typed,
 *    and it does not hand back the row's id either: the primary key is a global
 *    auto-increment, so returning it to an anonymous caller publishes a running
 *    count of every tenant's intake volume.
 *  - Caps are refusals, not truncations — a clipped `reason` is a clinical
 *    detail lost silently.
 *  - Flooding one organisation must not lock a visitor out of another's form.
 *  - And the flood cap must be UNROTATABLE: the limiter key has to be the
 *    tenant the controller resolves, not the header text it was spelled with,
 *    or eight rows an hour becomes eight rows an hour per spelling.
 *
 * NO PHI IN LOGS applies here too (.claude/rules/appointments.md): nothing in
 * this file may dump a payload, and nothing it exercises may either.
 */
class AppointmentRequestPublicAbuseSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'applicant_name' => 'Amal Yusuf',
            'phone' => '+15550001111',
            'email' => 'amal@example.com',
            'date_of_birth' => '1980-04-12',
            'reason' => 'Persistent cough for two weeks',
            'preferred_window' => 'Weekday mornings',
        ], $overrides);
    }

    private function submit(array $payload, ?int $masjidId = null)
    {
        $headers = [];

        if ($masjidId !== null) {
            $headers['masjid-id'] = (string) $masjidId;
        }

        return $this->postJson('/api/v1/appointment-requests', $payload, $headers);
    }

    /**
     * Submit with the `masjid-id` header spelled EXACTLY as given.
     *
     * submit() above casts through (string) (int), which is the one spelling an
     * attacker would never bother to use. The throttle rotation this file pins
     * lives entirely in the spellings that cast to the same tenant but are not
     * byte-identical, so a test for it has to be able to put arbitrary text in
     * the header.
     */
    private function submitWithRawHeader(array $payload, string $rawMasjidId)
    {
        return $this->postJson('/api/v1/appointment-requests', $payload, [
            'masjid-id' => $rawMasjidId,
        ]);
    }

    // ---------- the mail relay that must never exist ----------

    #[Test]
    public function submitting_a_request_sends_no_mail_to_anybody(): void
    {
        Mail::fake();
        Notification::fake();

        $this->submit($this->payload(['email' => 'a-stranger@example.com']), $this->masjidA->id)
            ->assertOk();

        // An unauthenticated caller picked that address. Nothing may be sent to
        // it, queued for it, or notified about it. See the class docblock.
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_request_carrying_a_third_partys_address_still_sends_them_nothing(): void
    {
        Mail::fake();
        Notification::fake();

        // The shape of the attack: the "applicant" is the victim's address and
        // the free-text fields are the message the attacker wants delivered.
        $this->submit($this->payload([
            'applicant_name' => 'URGENT: verify your account at http://example.invalid',
            'email' => 'victim@example.com',
            'reason' => 'Click the link above to keep your benefits.',
        ]), $this->masjidA->id)->assertOk();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    // ---------- no directory of who exists ----------

    #[Test]
    public function a_soft_deleted_organisation_is_refused_in_the_same_words_as_an_unknown_one(): void
    {
        $unknown = $this->submit($this->payload(), 999999)->assertStatus(404);

        $this->masjidA->delete();

        $offboarded = $this->submit($this->payload(), $this->masjidA->id)->assertStatus(404);

        // Byte-identical, so enumerating ids cannot separate "never existed"
        // from "was a customer until last month".
        $this->assertSame($unknown->json(), $offboarded->json());
        $this->assertSame(0, AppointmentRequest::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_malformed_payload_is_refused_before_the_organisation_is_looked_up(): void
    {
        // Validation runs at the FormRequest, ahead of the masjid lookup, so a
        // junk body answers 422 whether the id is real or not — the cheapest
        // probe available to a scanner tells it nothing.
        $real = $this->submit(['reason' => 'x'], $this->masjidA->id)->assertStatus(422);
        $fake = $this->submit(['reason' => 'x'], 999999)->assertStatus(422);

        $this->assertSame($real->json('status'), $fake->json('status'));
        $this->assertSame(
            array_keys((array) $real->json('data')),
            array_keys((array) $fake->json('data'))
        );
    }

    // ---------- nothing about the staff, nothing about the person ----------

    #[Test]
    public function the_response_names_no_staff_member_and_no_organisation_detail(): void
    {
        $body = $this->submit($this->payload(), $this->masjidA->id)->assertOk()->json();

        // The whole payload is {status, message, data:{id}}. A submitter learns
        // that it worked and nothing else — not who will handle it, not the
        // organisation's name, address, phone or Stripe posture.
        $this->assertSame(['id'], array_keys((array) $body['data']));

        $flattened = json_encode($body);
        $this->assertStringNotContainsString($this->masjidA->name, $flattened);
        $this->assertStringNotContainsString($this->masjidA->email, $flattened);
    }

    #[Test]
    public function the_success_payload_does_not_echo_the_applicants_details_back(): void
    {
        $flattened = json_encode(
            $this->submit($this->payload(), $this->masjidA->id)->assertOk()->json()
        );

        // A response that repeats the submission turns any place the response is
        // stored — a proxy log, a browser cache, an analytics beacon — into a
        // second copy of health-adjacent PII that nobody decided to keep.
        foreach (['Amal Yusuf', '+15550001111', 'amal@example.com', '1980-04-12', 'Persistent cough'] as $secret) {
            $this->assertStringNotContainsString($secret, $flattened);
        }
    }

    #[Test]
    public function a_caller_cannot_supply_the_operational_metadata_the_server_records(): void
    {
        $this->submit($this->payload([
            'ip_address' => '10.9.9.9',
            'user_agent' => 'ATTACKER-SUPPLIED',
            'id' => 424242,
        ]), $this->masjidA->id)->assertOk();

        // Found by query, not by an id out of the response: the success payload
        // deliberately hands back nothing that identifies the row (see
        // the_success_payload_hands_back_no_identifier below).
        $stored = AppointmentRequest::withoutMasjidScope()->sole();

        // These columns are the abuse-response record. A caller who can write
        // them can frame another visitor's connection for their own flood.
        $this->assertNotSame('10.9.9.9', $stored->ip_address);
        $this->assertNotSame('ATTACKER-SUPPLIED', $stored->user_agent);
        $this->assertNotSame(424242, $stored->id);
    }

    #[Test]
    public function the_success_payload_hands_back_no_identifier(): void
    {
        $first = $this->submit($this->payload(), $this->masjidA->id)->assertOk();
        $second = $this->submit($this->payload(), $this->masjidA->id)->assertOk();

        // `appointment_requests.id` is a GLOBAL auto-increment shared by every
        // tenant. Returned to an anonymous submitter it is a live counter: two
        // junk submissions a day apart subtract to the exact number of intake
        // rows the whole platform created in between, and for a single-clinic
        // deployment that IS the clinic's daily patient volume — sampleable
        // forever, by anyone who can reach the form.
        $this->assertNull($first->json('data.id'));
        $this->assertNull($second->json('data.id'));

        // Both rows really were written; the endpoint still works, it just does
        // not narrate the sequence.
        $this->assertSame(2, AppointmentRequest::withoutMasjidScope()->count());

        // And the two branches a submitter can land in are indistinguishable, so
        // the payload cannot be used to detect the honeypot either.
        $honeypot = $this->submit($this->payload(['website' => 'https://spam.example']), $this->masjidA->id)
            ->assertOk();

        $this->assertSame($first->json('data'), $honeypot->json('data'));
    }

    // ---------- caps refuse, they do not truncate ----------

    #[Test]
    public function an_oversized_reason_is_refused_rather_than_quietly_clipped(): void
    {
        $this->submit($this->payload(['reason' => str_repeat('a', 5001)]), $this->masjidA->id)
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        // Truncating would store a clinical description missing its ending and
        // tell the person it was received in full.
        $this->assertSame(0, AppointmentRequest::withoutMasjidScope()->count());
    }

    #[Test]
    public function every_free_text_field_has_a_cap_the_column_can_hold(): void
    {
        // Each of these is one character past its column width. MySQL in strict
        // mode would reject the write with a 500; SQLite would accept it and
        // hide the bug on CI. The boundary has to refuse them first, on both.
        $overlong = [
            'applicant_name' => str_repeat('n', 256),
            'phone' => str_repeat('5', 33),
            'email' => str_repeat('e', 250) . '@example.com',
            'preferred_window' => str_repeat('w', 256),
        ];

        foreach ($overlong as $field => $value) {
            $this->submit($this->payload([$field => $value]), $this->masjidA->id)
                ->assertStatus(422)
                ->assertJsonPath('status', 'failed');
        }

        $this->assertSame(0, AppointmentRequest::withoutMasjidScope()->count());
    }

    // ---------- the throttle protects the queue without becoming a weapon ----------

    #[Test]
    public function a_flood_is_cut_off_after_the_hourly_allowance(): void
    {
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->submit($this->payload(), $this->masjidA->id)->assertOk();
        }

        $this->submit($this->payload(), $this->masjidA->id)->assertStatus(429);

        $this->assertSame(8, AppointmentRequest::withoutMasjidScope()->count());
    }

    #[Test]
    public function flooding_one_organisation_does_not_lock_a_visitor_out_of_another(): void
    {
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->submit($this->payload(), $this->masjidA->id)->assertOk();
        }

        $this->submit($this->payload(), $this->masjidA->id)->assertStatus(429);

        // The limiter key is ip|resolved-masjid on purpose. Keyed on the IP alone, one
        // attacker could take every organisation's intake form offline at once —
        // and a whole shared network (a shelter, a campus) would lock itself out
        // of a clinic simply by using it.
        $this->submit($this->payload(), $this->masjidB->id)->assertOk();
    }

    #[Test]
    public function a_honeypot_submission_is_still_counted_against_the_flood_allowance(): void
    {
        // A bot that trips the honeypot writes nothing, so nothing in the
        // database records the attempt. The throttle is the only thing left that
        // remembers it happened — if the honeypot short-circuit ever moved ahead
        // of the limiter, a scripted submitter would get unlimited free tries at
        // discovering which field is the trap.
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->submit($this->payload(['website' => 'https://spam.example']), $this->masjidA->id)
                ->assertOk();
        }

        $this->submit($this->payload(), $this->masjidA->id)->assertStatus(429);
        $this->assertSame(0, AppointmentRequest::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_allowance_cannot_be_rotated_by_respelling_the_masjid_header(): void
    {
        // Every one of these is masjid A to the controller: it resolves the
        // tenant with `(int) $request->header('masjid-id')`, so each spelling
        // passes `Masjid::whereKey()->exists()` and writes into the SAME
        // clinic's triage queue. The limiter therefore has to agree with that
        // cast, or the cap is decorative — keyed on the raw string, an attacker
        // has an unbounded supply of fresh eight-row allowances against one
        // organisation, each row carrying up to 5,000 characters of free text
        // that reads to staff exactly like a real intake.
        $id = (int) $this->masjidA->id;

        $spellings = [
            (string) $id,
            '0' . $id,
            '00' . $id,
            '+' . $id,
            $id . 'x',
            ' ' . $id,
            $id . ' ',
        ];

        // Eight requests spread across the spellings — the whole hourly
        // allowance, spent under seven different header strings.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->submitWithRawHeader($this->payload(), $spellings[$attempt % count($spellings)])
                ->assertOk();
        }

        // The ninth is refused NO MATTER how it is spelled — including spellings
        // already used and one used so far only once.
        foreach ($spellings as $spelling) {
            $this->submitWithRawHeader($this->payload(), $spelling)->assertStatus(429);
        }

        // And the canonical spelling is refused too, so the bucket is one bucket.
        $this->submit($this->payload(), $this->masjidA->id)->assertStatus(429);

        // Exactly the allowance landed in the queue, not a multiple of it.
        $this->assertSame(8, AppointmentRequest::withoutMasjidScope()->count());
        $this->assertSame(
            8,
            AppointmentRequest::withoutMasjidScope()->where('masjid_id', $id)->count()
        );
    }
}
