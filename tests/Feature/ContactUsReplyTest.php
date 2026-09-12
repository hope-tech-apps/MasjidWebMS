<?php

namespace Tests\Feature;

use App\Mail\ContactRequestReply;
use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReason;
use App\Models\ContactUsReply;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Answering somebody who wrote in, over
 * /api/admin/masjids/{masjid_id}/contact-requests (T-042d).
 *
 * The reply endpoint used to mail the sender and return. Nothing was written
 * down, so the office could not tell an answered message from an unopened one
 * and two people wrote back to the same stranger. These tests pin the four
 * things that fix depends on:
 *
 *  - a reply is SAVED, with who sent it and when, and a second reply is
 *    appended rather than replacing the first;
 *  - the answered flag follows DELIVERY, not intention — a send that fails
 *    leaves the message unanswered, because a message marked answered that
 *    nobody was actually told about is the exact failure the flag exists to
 *    prevent;
 *  - triage is a LABEL, not a state machine: the flag can be set without
 *    sending anything and cleared again, in any order
 *    (.claude/rules/appointments.md);
 *  - one reply reaches a member of the public ONCE, however many times the
 *    button is pressed, INCLUDING when the second press lands while the first
 *    send is still at the relay — the case a sequential test cannot reach and
 *    the one the old read-then-send guard got wrong.
 *
 * Tenancy is hand-scoped three joins deep here (contacter -> mobileAppUser ->
 * masjid_id), so the cross-tenant case is checked on both new verbs: another
 * organisation's message id under this admin's own route is a 404, not a 403 and
 * not a leak.
 */
class ContactUsReplyTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    private ContactUsMessage $messageA;
    private ContactUsMessage $messageB;

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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->messageA = $this->seedMessage($this->masjidA, 'Janazah', 'When is the janazah?');
        $this->messageB = $this->seedMessage($this->masjidB, 'Donations', 'How do I give zakat?', 'sender-b@example.invalid');
    }

    // ---------- the reply is saved ----------

    #[Test]
    public function a_reply_is_saved_with_who_sent_it_and_when(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'The janazah is after Dhuhr.',
            'idempotency_key' => 'key-one',
        ])->assertOk();

        $reply = ContactUsReply::where('contact_us_message_id', $this->messageA->id)->firstOrFail();

        $this->assertSame('The janazah is after Dhuhr.', $reply->body);
        $this->assertSame($this->adminA->id, $reply->actor_user_id);
        $this->assertSame($this->adminA->name, $reply->actor_name);
        $this->assertSame('sender-a@example.invalid', $reply->sent_to);
        // sent_at, not created_at: the delivery fact, stamped once the mailer
        // took it.
        $this->assertNotNull($reply->sent_at);
    }

    #[Test]
    public function sending_a_reply_marks_the_message_answered(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'Wa alaikum assalam.',
            'idempotency_key' => 'key-answered',
        ])->assertOk();

        $this->assertNotNull($response->json('data.answered_at'));
        $this->assertSame($this->adminA->name, $response->json('data.answered_by_name'));

        $this->messageA->refresh();
        $this->assertTrue($this->messageA->isAnswered());
        $this->assertSame($this->adminA->id, $this->messageA->answered_by_user_id);
    }

    #[Test]
    public function a_second_reply_is_appended_rather_than_replacing_the_first(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'The janazah is after Dhuhr.',
            'idempotency_key' => 'first',
        ])->assertOk();

        $response = $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'Correction: after Asr.',
            'idempotency_key' => 'second',
        ])->assertOk();

        $bodies = ContactUsReply::where('contact_us_message_id', $this->messageA->id)
            ->orderBy('id')
            ->pluck('body')
            ->all();

        $this->assertSame(['The janazah is after Dhuhr.', 'Correction: after Asr.'], $bodies);
        // The payload the screen redraws from carries the whole thread, oldest
        // first — the second person to open this must be able to read the first
        // person's answer.
        $this->assertCount(2, $response->json('data.replies'));
        $this->assertSame('The janazah is after Dhuhr.', $response->json('data.replies.0.body'));
    }

    #[Test]
    public function the_actor_name_survives_the_staff_member_being_deleted(): void
    {
        // The FK nulls; the snapshot does not. Without the snapshot, "who
        // answered this person?" stops having an answer the moment a staff
        // account is removed, which puts the office back where it started.
        $leaver = User::factory()->create([
            'name' => 'Departed Volunteer',
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $reply = ContactUsReply::create([
            'contact_us_message_id' => $this->messageA->id,
            'body' => 'Answered by someone who has since left.',
            'sent_to' => 'sender-a@example.invalid',
            'actor_user_id' => $leaver->id,
            'actor_name' => $leaver->name,
            'actor_email' => $leaver->email,
            'idempotency_key' => 'departed',
            'sent_at' => now(),
        ]);

        // forceDelete, not delete: User soft-deletes, and only a real row
        // removal exercises the nullOnDelete the schema promises.
        $leaver->forceDelete();

        $this->assertNull($reply->refresh()->actor_user_id);
        $this->assertSame('Departed Volunteer', $reply->actor_name);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->url() . '/' . $this->messageA->id)
            ->assertOk()
            ->assertJsonPath('data.replies.0.actor_name', 'Departed Volunteer');
    }

    // ---------- delivery decides the answered flag ----------

    #[Test]
    public function a_reply_that_fails_to_send_does_not_mark_the_message_answered(): void
    {
        // The ordering decision, pinned. The reply row is written FIRST so the
        // admin's words are never lost, and `sent_at` + `answered_at` are
        // stamped only once the mailer has taken it. Stamping first would leave
        // a message reading "answered" that nobody was told about.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('relay down'));
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'This will not get out.',
            'idempotency_key' => 'doomed',
        ])->assertStatus(500);

        $reply = ContactUsReply::where('contact_us_message_id', $this->messageA->id)->firstOrFail();

        // Recorded, so nothing the admin typed is lost...
        $this->assertSame('This will not get out.', $reply->body);
        // ...but not delivered, and therefore not answered.
        $this->assertNull($reply->sent_at);
        $this->assertNull($this->messageA->refresh()->answered_at);
        // ...and the send claim is RELEASED, because this send is over and did
        // not happen. A claim left standing would make the reply unsendable
        // until it went stale, and the retry below is the documented path out of
        // a relay outage.
        $this->assertNull($reply->sending_at);
    }

    #[Test]
    public function a_retry_after_a_failed_send_delivers_the_reply_without_filing_a_second(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('relay down'));
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'Please call the office.',
            'idempotency_key' => 'retried',
        ])->assertStatus(500);

        // The relay comes back. The SAME key is re-used, because the reply was
        // never delivered — a key is rotated only after a successful send.
        //
        // `Mail::fake()` builds its MailFake AROUND the manager the facade is
        // currently holding — which here is the throwing Mockery double — and
        // the first thing it does is ask that manager for its default driver.
        // The double has no expectation for it, so the fake cannot even be
        // constructed. One expectation is enough: from the swap onwards every
        // send goes to the fake, not the double.
        Mail::shouldReceive('getDefaultDriver')->andReturn('array');

        Mail::fake();

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'Please call the office.',
            'idempotency_key' => 'retried',
        ])->assertOk();

        $this->assertSame(1, ContactUsReply::where('contact_us_message_id', $this->messageA->id)->count());
        Mail::assertSentCount(1);
        $this->assertNotNull($this->messageA->refresh()->answered_at);
    }

    #[Test]
    public function a_double_click_sends_one_email_and_files_one_reply(): void
    {
        // A stranger who wrote in must not receive the same answer twice. The
        // disabled button is a courtesy; this is the guarantee, and it holds for
        // a retried request and a second open tab too.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $payload = [
            'reply' => 'Jazak Allahu khayran for writing in.',
            'idempotency_key' => 'one-click',
        ];

        $first = $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', $payload)->assertOk();
        $second = $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', $payload)->assertOk();

        Mail::assertSentCount(1);
        $this->assertSame(1, ContactUsReply::where('contact_us_message_id', $this->messageA->id)->count());

        // And the two answers do not read the same. The screen announces "Reply
        // Sent!" off `meta.sent_now`, so a replay that claimed to have just sent
        // something would tell the office an email went out that did not.
        $this->assertTrue($first->json('meta.sent_now'));
        $this->assertFalse($second->json('meta.sent_now'));
    }

    #[Test]
    public function the_right_to_send_is_claimed_before_the_mailer_is_called(): void
    {
        // The ordering IS the fix, so it is pinned directly: at the instant the
        // mailer is handed the reply, the stored row must already say a request
        // owns this send. Under the read-then-send shape this replaced,
        // `sending_at` did not exist and `sent_at` was stamped only afterwards,
        // so the row said "nobody is sending this" for the whole length of the
        // SMTP round-trip and any second request read that and sent its own
        // copy. Move the claim back after the send and $claimedDuringSend is
        // null and this fails.
        Sanctum::actingAs($this->adminA);

        $claimedDuringSend = null;
        $deliveredDuringSend = null;

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andReturnUsing(function () use (&$claimedDuringSend, &$deliveredDuringSend) {
            $row = ContactUsReply::where('contact_us_message_id', $this->messageA->id)->first();

            $claimedDuringSend = $row?->sending_at;
            $deliveredDuringSend = $row?->sent_at;
        });

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'Claimed before it goes.',
            'idempotency_key' => 'claim-first',
        ])->assertOk();

        $this->assertNotNull($claimedDuringSend, 'The send was not claimed before the mailer was called.');
        // And the claim is NOT `sent_at` moved earlier: a reply must never read
        // "delivered" before the mailer has taken it, or a crash mid-send leaves
        // a permanent record that somebody was answered when they were not.
        $this->assertNull($deliveredDuringSend);

        $reply = ContactUsReply::where('contact_us_message_id', $this->messageA->id)->firstOrFail();
        $this->assertNotNull($reply->sent_at);
        $this->assertNull($reply->sending_at);
    }

    #[Test]
    public function a_second_request_that_arrives_while_the_first_is_still_at_the_relay_sends_nothing(): void
    {
        // THE guarantee the double-send guard is for, and the one a sequential
        // test cannot reach. `ContactRequestReply` is not queued, so the request
        // BLOCKS in a real SMTP round-trip that can take seconds; a browser or
        // proxy gives up at 30s and the admin presses Send again, or the same
        // message is open in a second tab. The retry arrives while the first
        // send is still in flight.
        //
        // The second request is fired from INSIDE the mailer call, which is
        // exactly that window. If the guard ever goes back to reading `sent_at`
        // and then sending — the shape this replaced — the inner request finds a
        // row that reads "not sent" (truthfully: the outer one has not finished)
        // and mails the person a second copy. Then $sends is 2 and this fails.
        Sanctum::actingAs($this->adminA);

        $payload = [
            'reply' => 'Jazak Allahu khayran for writing in.',
            'idempotency_key' => 'in-flight',
        ];

        $sends = 0;
        $retry = null;

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andReturnUsing(function () use (&$sends, &$retry, $payload) {
            $sends++;

            if ($sends === 1) {
                // Asserted after the outer request returns, never in here: the
                // controller catches Throwable around the send, so a failed
                // assertion thrown from this closure would be swallowed into a
                // 500 and reported as something else entirely.
                $retry = $this->postJson(
                    $this->url() . '/' . $this->messageA->id . '/reply',
                    $payload
                );
            }
        });

        $first = $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', $payload)->assertOk();

        // One email to the member of the public, not two.
        $this->assertSame(1, $sends);

        // The loser sent nothing and said so, rather than reporting a delivery
        // it did not make or a failure that did not happen.
        $this->assertNotNull($retry, 'The re-entrant request never ran.');
        $retry->assertStatus(409);
        $this->assertFalse($retry->json('meta.sent_now'));
        $this->assertSame($this->messageA->id, $retry->json('data.id'));

        // The winner finished normally: one row, delivered, message answered.
        $this->assertTrue($first->json('meta.sent_now'));
        $this->assertSame(1, ContactUsReply::where('contact_us_message_id', $this->messageA->id)->count());

        $reply = ContactUsReply::where('contact_us_message_id', $this->messageA->id)->firstOrFail();
        $this->assertNotNull($reply->sent_at);
        $this->assertNull($reply->sending_at);
        $this->assertNotNull($this->messageA->refresh()->answered_at);
    }

    #[Test]
    public function the_same_reply_from_a_second_tab_collapses_to_one_email_without_any_client_key(): void
    {
        // The key used to be minted per modal-open in the SPA's component state,
        // so a second tab — or simply reopening the modal — produced a different
        // key and the guard did nothing in the very cases four docblocks named.
        // The SPA now sends no key at all and the server derives one from the
        // message and the exact text, which every tab computes identically.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $payload = ['reply' => 'Wa alaikum assalam, the office opens at 9.'];

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', $payload)->assertOk();
        $second = $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', $payload)->assertOk();

        Mail::assertSentCount(1);
        $this->assertSame(1, ContactUsReply::where('contact_us_message_id', $this->messageA->id)->count());
        $this->assertFalse($second->json('meta.sent_now'));
    }

    #[Test]
    public function a_genuinely_different_answer_is_still_sent_when_no_client_key_is_supplied(): void
    {
        // The other half of deriving the key from the text: it must not collapse
        // two replies that are not the same reply. A guard that quietly refuses
        // to send the second thing staff wrote would be worse than the duplicate
        // it prevents.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'The janazah is after Dhuhr.',
        ])->assertOk();

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'Correction: after Asr.',
        ])->assertOk();

        Mail::assertSentCount(2);
        $this->assertSame(2, ContactUsReply::where('contact_us_message_id', $this->messageA->id)->count());
    }

    #[Test]
    public function a_claim_left_behind_by_a_request_that_died_expires_so_the_reply_can_be_sent(): void
    {
        // A request killed between claiming and sending (a php-fpm terminate, a
        // fatal) leaves `sending_at` set with nothing behind it. Without an
        // expiry that reply could never be sent again from the screen, so the
        // claim goes stale — but only after long enough that it cannot expire
        // while a slow relay is still working, which would put a second copy in
        // a stranger's inbox.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $fresh = ContactUsReply::create([
            'contact_us_message_id' => $this->messageA->id,
            'body' => 'Someone is sending this right now.',
            'sent_to' => 'sender-a@example.invalid',
            'idempotency_key' => 'held',
            'sending_at' => now()->subSeconds(30),
        ]);

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'Someone is sending this right now.',
            'idempotency_key' => 'held',
        ])->assertStatus(409);

        Mail::assertNothingSent();
        $this->assertNull($fresh->refresh()->sent_at);

        // The same row, with a claim old enough that nothing can still be
        // holding it.
        $fresh->forceFill(['sending_at' => now()->subMinutes(30)])->save();

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'Someone is sending this right now.',
            'idempotency_key' => 'held',
        ])->assertOk();

        Mail::assertSentCount(1);
        $this->assertSame(1, ContactUsReply::where('contact_us_message_id', $this->messageA->id)->count());
        $this->assertNotNull($fresh->refresh()->sent_at);
    }

    #[Test]
    public function a_validation_failure_is_not_shaped_like_a_reply_that_was_saved_but_not_sent(): void
    {
        // The screen decides "your words were saved but the email did not go" by
        // looking at the failure body. BaseFormRequest answers a validation
        // error with {status: 'failed', data: <error bag>} — the same `data` key
        // the not-delivered answer uses — so a client keying on the presence of
        // `data` alone reports a refusal that wrote NOTHING as a saved reply,
        // and overwrites the row's answered state with an error bag. This pins
        // the two shapes apart at the source.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => str_repeat('a', 5001),
        ])->assertStatus(422);

        $this->assertSame('failed', $response->json('status'));
        // No message state anywhere in it: the "saved but not sent" envelope is
        // {status: 'error', data: {id, answered_at, replies}}.
        $this->assertNull($response->json('data.id'));
        $this->assertNotNull($response->json('data.reply'));

        Mail::assertNothingSent();
        $this->assertSame(0, ContactUsReply::where('contact_us_message_id', $this->messageA->id)->count());
        $this->assertNull($this->messageA->refresh()->answered_at);
    }

    #[Test]
    public function a_reply_of_nothing_but_whitespace_is_refused_rather_than_emailed(): void
    {
        // `min:1` is applied to the NORMALISED text, because the normalised text
        // is what gets emailed and what the derived idempotency key is a hash
        // of. Unnormalised, a textarea full of newlines passes and the person
        // who wrote in receives an empty email from the masjid.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => "  \r\n \t ",
        ])->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertSame(0, ContactUsReply::where('contact_us_message_id', $this->messageA->id)->count());
    }

    #[Test]
    public function the_reply_that_is_stored_is_byte_for_byte_the_one_that_was_emailed(): void
    {
        // The confirmation step shows the admin `replyText.trim()`; the mail
        // carries the stored body; the derived key is a hash of it. All three
        // have to be the same string or the guard misses on a retry and the
        // audit record stops being the record of what was sent.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => "\r\n The janazah is after Dhuhr.\r\nPlease come early. \n",
        ])->assertOk();

        $reply = ContactUsReply::where('contact_us_message_id', $this->messageA->id)->firstOrFail();

        $this->assertSame("The janazah is after Dhuhr.\nPlease come early.", $reply->body);

        Mail::assertSent(
            ContactRequestReply::class,
            fn (ContactRequestReply $mail) => $mail->replyBody === $reply->body
        );
    }

    #[Test]
    public function a_contact_request_with_no_email_address_is_refused_before_anything_is_written(): void
    {
        // contact_us_accounts.email is written by an UNAUTHENTICATED intake, so
        // it can be any string. An unusable one is the documented 422, never a
        // 500 from handing the mailer something it cannot parse.
        $message = $this->seedMessage($this->masjidA, 'Anonymous', 'No way to reach me.', 'not-an-address');

        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $message->id . '/reply', [
            'reply' => 'Nowhere to send this.',
            'idempotency_key' => 'nowhere',
        ])->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertSame(0, ContactUsReply::where('contact_us_message_id', $message->id)->count());
        $this->assertNull($message->refresh()->answered_at);
    }

    // ---------- triage is a label, not a state machine ----------

    #[Test]
    public function staff_can_mark_a_message_answered_without_sending_a_reply(): void
    {
        // The ordinary case: somebody rang the person back.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $this->patchJson($this->url() . '/' . $this->messageA->id . '/answered', ['answered' => '1'])
            ->assertOk()
            ->assertJsonPath('data.answered_by_name', $this->adminA->name);

        Mail::assertNothingSent();

        $this->messageA->refresh();
        $this->assertTrue($this->messageA->isAnswered());
        $this->assertSame($this->adminA->id, $this->messageA->answered_by_user_id);
    }

    #[Test]
    public function an_answered_message_can_be_marked_unanswered_again(): void
    {
        // No transition guard, on purpose: the sender writes back and the thread
        // is live again. The reply history is NOT erased by doing so.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'Answered once.',
            'idempotency_key' => 'reopened',
        ])->assertOk();

        $this->patchJson($this->url() . '/' . $this->messageA->id . '/answered', ['answered' => '0'])
            ->assertOk()
            ->assertJsonPath('data.answered_at', null);

        $this->messageA->refresh();
        $this->assertFalse($this->messageA->isAnswered());
        $this->assertNull($this->messageA->answered_by_user_id);
        $this->assertNull($this->messageA->answered_by_name);

        // What was said stays said.
        $this->assertSame(1, ContactUsReply::where('contact_us_message_id', $this->messageA->id)->count());
    }

    #[Test]
    public function the_answered_toggle_refuses_the_strings_that_laravels_boolean_rule_rejects(): void
    {
        // "true"/"false" fail Laravel's `boolean` rule, and sending them once
        // blocked every live Jummah-lunch order. The SPA sends "1"/"0"; this
        // pins that a regression there is a clean 422 and not a silent no-op.
        Sanctum::actingAs($this->adminA);

        $this->patchJson($this->url() . '/' . $this->messageA->id . '/answered', ['answered' => 'true'])
            ->assertStatus(422);

        $this->assertNull($this->messageA->refresh()->answered_at);
    }

    // ---------- tenancy ----------

    #[Test]
    public function another_masjids_contact_request_is_a_404_not_a_403(): void
    {
        // Both NEW verbs, under this admin's OWN route. The message is invisible
        // to this tenant, so the scoped lookup misses and findOrFail answers 404
        // — and it must not be swallowed into a 500 by the surrounding catch.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->messageB->id . '/reply', [
            'reply' => 'Not mine to answer.',
            'idempotency_key' => 'cross-tenant',
        ])->assertStatus(404);

        $this->patchJson($this->url() . '/' . $this->messageB->id . '/answered', ['answered' => '1'])
            ->assertStatus(404);

        Mail::assertNothingSent();
        $this->assertNull($this->messageB->refresh()->answered_at);
        $this->assertSame(0, ContactUsReply::count());
    }

    #[Test]
    public function the_listing_shows_only_this_organizations_messages_with_their_answered_state(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url())->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($this->messageA->id, $response->json('data.data.0.id'));
        $this->assertNull($response->json('data.data.0.answered_at'));
    }

    // ---------- what the screen is allowed to promise ----------

    #[Test]
    public function the_screen_is_told_the_exact_subject_line_the_reply_will_carry(): void
    {
        // The confirmation step shows this before anything is sent. It has to be
        // the string the Mailable actually uses: a TypeScript re-implementation
        // of the format would drift and the screen would be misdescribing mail
        // going to a member of the public.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $subject = $this->getJson($this->url())
            ->assertOk()
            ->json('meta.reply_subjects.' . $this->messageA->id);

        $this->assertSame('Re: Janazah - ' . $this->masjidA->name, $subject);

        $this->postJson($this->url() . '/' . $this->messageA->id . '/reply', [
            'reply' => 'After Dhuhr.',
            'idempotency_key' => 'subject-check',
        ])->assertOk();

        Mail::assertSent(
            ContactRequestReply::class,
            fn (ContactRequestReply $mail) => $mail->envelope()->subject === $subject
        );
    }

    // ------------------------------------------------------------- helpers

    private function url(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id . '/contact-requests';
    }

    private function seedMessage(
        Masjid $masjid,
        string $reasonText,
        string $body,
        string $senderEmail = 'sender-a@example.invalid'
    ): ContactUsMessage {
        $device = MobileAppUser::create([
            'device_id' => 'device-' . uniqid(),
            'masjid_id' => $masjid->id,
            'user_agent' => 'test',
        ]);

        $account = ContactUsAccount::create([
            'mobile_app_user_id' => $device->id,
            'email' => $senderEmail,
            'name' => 'Sender',
            'phone' => '+15550000001',
        ]);

        // contact_us_reasons is a GLOBAL table with a unique `text`, so the two
        // organisations share the vocabulary. Not this task to fix; see
        // App\Support\ContactUsNotifier.
        $reason = ContactUsReason::firstOrCreate(
            ['text' => $reasonText],
            ['show_to_users' => false]
        );

        return ContactUsMessage::create([
            'contact_us_account_id' => $account->id,
            'contact_us_reason_id' => $reason->id,
            'message' => $body,
        ]);
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.invalid',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
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
}
