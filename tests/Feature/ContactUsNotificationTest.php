<?php

namespace Tests\Feature;

use App\Mail\ContactUsMessageReceived;
use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Support\ContactUsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A contact-us message nobody is told about is only half-received.
 *
 * Until T-042d both intake doors wrote a row and returned. Nothing was sent to
 * anybody, so a message existed only on a dashboard screen the office is not
 * obliged to open — which is how a request for help sits unread for a week.
 *
 * Four guarantees are pinned here, and each one is a way the fix could go
 * wrong rather than a restatement that it works:
 *
 *  1. BOTH doors notify. The website and the mobile app write the same row, and
 *     a fix applied to one of them is invisible from the other. Same shape as
 *     FormDoorEquivalenceTest.
 *  2. Mail can never cost somebody their message. The sender is anonymous and
 *     has already been told the message was received; a relay outage must not
 *     turn that into a 500.
 *  3. The notification goes to the organisation that received the message and
 *     to nowhere else. This endpoint is UNAUTHENTICATED, so if any part of the
 *     payload could steer delivery, the feature would be a way to make Manara
 *     mail arbitrary strangers a message of the sender's choosing.
 *  4. An organisation with no usable contact address still accepts the message,
 *     and the fact that nobody could be told is logged rather than silent.
 *
 * ## assertQueued, not assertSent
 *
 * ContactUsMessageReceived is ShouldQueue, so PendingMail hands it to the queue
 * rather than the mailer and MailFake files it under QUEUED. `Mail::assertSent`
 * does not merely miss it — Laravel fails the assertion with "did you mean
 * assertQueued()". Same reading as FormNotificationTest, whose
 * FormResponseSubmitted is queued for the same reason: the person waiting on the
 * HTTP response must never wait on SMTP. If this Mailable is ever made
 * synchronous, every assertion in this file flips back.
 */
class ContactUsNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

    /** Serial for the unique device ids the helper mints. */
    private static int $seeded = 0;

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

        $this->masjidA = $this->makeMasjid(['email' => 'office-a@example.invalid']);
        $this->masjidB = $this->makeMasjid(['email' => 'office-b@example.invalid']);
    }

    #[Test]
    public function staff_are_emailed_when_a_message_arrives_through_the_website(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/contact-us', $this->payload('web-device'), [
            'masjid-id' => (string) $this->masjidA->id,
        ])->assertOk();

        Mail::assertQueued(
            ContactUsMessageReceived::class,
            fn (ContactUsMessageReceived $mail) => $mail->hasTo('office-a@example.invalid')
        );
    }

    #[Test]
    public function staff_are_emailed_when_a_message_arrives_through_the_mobile_app(): void
    {
        // The door-equivalence guarantee. A notification wired into only one
        // intake path leaves the other silent, and nothing about the admin
        // screen would ever reveal which messages came through which door.
        Mail::fake();

        // The mobile door requires the device to already be registered here.
        MobileAppUser::create([
            'device_id' => 'mobile-device',
            'masjid_id' => $this->masjidA->id,
            'user_agent' => 'test',
        ]);

        $this->postJson(
            "/api/mobile/masjids/{$this->masjidA->id}/contact-us",
            $this->payload('mobile-device')
        )->assertOk();

        Mail::assertQueued(
            ContactUsMessageReceived::class,
            fn (ContactUsMessageReceived $mail) => $mail->hasTo('office-a@example.invalid')
        );
    }

    #[Test]
    public function a_mail_failure_never_costs_the_sender_their_message(): void
    {
        // A relay that refuses the notification. The person on the other end has
        // already been told their message was received, and nothing that happens
        // to the email may make that untrue.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('relay down'));

        $this->postJson('/api/v1/contact-us', $this->payload('unlucky-device'), [
            'masjid-id' => (string) $this->masjidA->id,
        ])->assertOk();

        $this->assertSame(1, ContactUsMessage::count());
    }

    #[Test]
    public function the_notification_goes_to_the_organization_that_received_the_message_and_no_other(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/contact-us', $this->payload('scoped-device'), [
            'masjid-id' => (string) $this->masjidB->id,
        ])->assertOk();

        Mail::assertQueued(
            ContactUsMessageReceived::class,
            fn (ContactUsMessageReceived $mail) => $mail->hasTo('office-b@example.invalid')
                && ! $mail->hasTo('office-a@example.invalid')
        );
    }

    #[Test]
    public function the_sender_cannot_choose_who_hears_about_their_message(): void
    {
        // The address list is derived from the organisation's own stored contact
        // address and from nothing in the request. If this ever stops being
        // true, an unauthenticated endpoint becomes a way to mail a stranger
        // text of the sender's choosing from the organisation's domain.
        Mail::fake();

        $this->postJson('/api/v1/contact-us', $this->payload('spoofer-device', [
            'email' => 'attacker@evil.invalid',
            'name' => 'attacker@evil.invalid',
            'reason_text' => 'attacker@evil.invalid',
        ]), ['masjid-id' => (string) $this->masjidA->id])->assertOk();

        Mail::assertQueued(
            ContactUsMessageReceived::class,
            fn (ContactUsMessageReceived $mail) => $mail->hasTo('office-a@example.invalid')
                && ! $mail->hasTo('attacker@evil.invalid')
        );
    }

    #[Test]
    public function a_message_for_an_organization_with_no_contact_address_is_still_accepted_and_logged(): void
    {
        // masjids.email is NOT NULL, so "no contact address" in practice is a
        // blank or unusable one. The message must still land, and the fact that
        // nobody could be told must be findable — it is invisible from the
        // outside otherwise: the form works and the row appears.
        $this->masjidA->forceFill(['email' => ''])->save();

        Mail::fake();
        Log::spy();

        $this->postJson('/api/v1/contact-us', $this->payload('orphan-device'), [
            'masjid-id' => (string) $this->masjidA->id,
        ])->assertOk();

        $this->assertSame(1, ContactUsMessage::count());
        Mail::assertNothingQueued();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'no notification recipients')
                && ($context['masjid_id'] ?? null) === $this->masjidA->id)
            ->once();
    }

    #[Test]
    public function the_notification_names_a_blank_reason_rather_than_leaving_the_subject_open(): void
    {
        // contact_us_reasons is a GLOBAL table fed by unauthenticated free text,
        // so the reason reaching a subject line is neither trusted nor
        // guaranteed to be present.
        $message = $this->seedMessageFor($this->masjidA, ['reason' => null]);

        Mail::fake();

        ContactUsNotifier::received($message, $this->masjidA, ContactUsNotifier::SOURCE_WEBSITE);

        Mail::assertQueued(
            ContactUsMessageReceived::class,
            fn (ContactUsMessageReceived $mail) => $mail->reason === 'General enquiry'
        );
    }

    #[Test]
    public function the_office_cannot_be_flooded_with_notifications(): void
    {
        // The per-IP throttles on both doors stop one connection; this is the
        // per-ORGANISATION ceiling that stops a distributed flood filling one
        // office's inbox (and getting the sending domain rate-limited, which
        // would take every other Manara email down with it). The messages
        // themselves are still accepted — only the nudge is dropped.
        Mail::fake();

        for ($i = 0; $i < 35; $i++) {
            ContactUsNotifier::received(
                $this->seedMessageFor($this->masjidA),
                $this->masjidA,
                ContactUsNotifier::SOURCE_WEBSITE
            );
        }

        Mail::assertQueuedCount(30);
    }

    // ------------------------------------------------------------- helpers

    /** @param array<string,mixed> $overrides */
    private function payload(string $deviceId, array $overrides = []): array
    {
        return array_merge([
            'device_id' => $deviceId,
            'name' => 'Someone',
            'email' => 'someone@example.invalid',
            'phone' => '+15550000009',
            'reason_text' => 'General enquiry',
            'message' => 'Assalamu alaikum, when is the janazah?',
        ], $overrides);
    }

    /**
     * A committed message with its relations attached, for the cases that call
     * the notifier directly rather than through an HTTP door.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function seedMessageFor(Masjid $masjid, array $overrides = []): ContactUsMessage
    {
        // A COUNTER, not uniqid(): mobile_app_users.device_id is globally unique
        // and uniqid() repeats when two calls land in the same microsecond,
        // which the 35-iteration flood loop below is exactly the shape to hit.
        // A test that fails once a fortnight on a unique-key clash teaches
        // nobody anything.
        $device = MobileAppUser::create([
            'device_id' => 'seed-' . (++self::$seeded),
            'masjid_id' => $masjid->id,
            'user_agent' => 'test',
        ]);

        $account = ContactUsAccount::create([
            'mobile_app_user_id' => $device->id,
            'email' => 'sender@example.invalid',
            'name' => 'Sender',
            'phone' => '+15550000001',
        ]);

        $message = ContactUsMessage::create([
            'contact_us_account_id' => $account->id,
            'contact_us_reason_id' => null,
            'message' => 'Assalamu alaikum.',
        ]);

        return $message
            ->setRelation('contacter', $account)
            ->setRelation('reason', $overrides['reason'] ?? null);
    }

    /** @param array<string,mixed> $overrides */
    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@example.invalid',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }
}
