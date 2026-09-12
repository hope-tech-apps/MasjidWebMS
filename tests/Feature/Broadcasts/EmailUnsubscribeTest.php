<?php

namespace Tests\Feature\Broadcasts;

use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\EmailSuppression;
use App\Models\Form;
use App\Models\Fund;
use App\Models\Masjid;
use App\Services\Broadcast\Channels\EmailChannel;
use App\Services\Broadcast\EmailSuppressionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The unsubscribe link on broadcast email (T-042c).
 *
 * This is a compliance obligation — CAN-SPAM's functioning opt-out mechanism,
 * plus the Gmail/Yahoo bulk-sender one-click requirement — so the guarantees
 * below are written as the promises made to a congregant, not as coverage of a
 * class.
 *
 *  1. Every broadcast email carries a link that works, with NO login and NO
 *     session. A person who cannot sign in must still be able to leave.
 *  2. A GET does not unsubscribe anybody. SafeLinks, Gmail's prefetcher and
 *     corporate scanners follow links in email; a GET that acted would opt out
 *     people who never clicked, invisibly.
 *  3. The POST stops broadcast email from ONE organisation, immediately and
 *     durably — surviving the contact row being merged away and re-imported,
 *     which is the exact path that would otherwise resurrect a clean, mailable
 *     record.
 *  4. TRANSACTIONAL MAIL IS UNAFFECTED. A donor who unsubscribed still gets
 *     their receipt; a family still gets a registration confirmation. This is
 *     structural — the suppression is read in one place, the audience resolver —
 *     and both halves are pinned here, behaviourally and statically.
 *  5. The link is unguessable and names nobody. There is no id in the URL to
 *     increment, editing it unsubscribes nobody, and every refusal renders the
 *     same page naming no organisation and no address.
 *  6. An admin cannot undo somebody's unsubscribe, and neither can the link
 *     printed in the email: resuming mail needs a second, purpose-scoped token
 *     minted only on the landing page, so an automated one-click POST can only
 *     ever move in the safe direction.
 *  7. The admin is TOLD how many people were left out, so a shrinking count
 *     reads as the organisation's unsubscribe rate rather than as lost data.
 *  8. The one-click POST can actually ARRIVE: the CSRF exemption it needs, and
 *     the throttle key it is measured against, are both pinned here in ways
 *     that break when they are wrong. See
 *     `the_unsubscribe_paths_are_exempt_from_csrf_so_a_mailbox_provider_can_reach_them`
 *     for why the ordinary `$this->post()` assertions in this file cannot do it.
 *
 * Mail::fake intercepts the mailer for most of this file. The transactional
 * guarantee (4) is the exception and says why in its own docblock: a fake never
 * fires `MessageSending`, so the regression it exists to catch is invisible to
 * one.
 */
class EmailUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    /**
     * The REAL mail manager, captured before `Mail::fake()` replaces it.
     *
     * Kept so one test can put it back: a fake records mailables without ever
     * running the mailer, so `MessageSending` never fires and a listener that
     * suppressed transactional mail would be invisible. See `useRealMailer()`.
     *
     * @var \Illuminate\Mail\MailManager
     */
    private $realMailManager;

    /** Stripe's signing secret for the receipt path driven in guarantee 4. */
    private string $webhookSecret = 'whsec_unsubscribe_test';

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

        config([
            'services.stripe.webhook_secret' => $this->webhookSecret,
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.currency' => 'usd',
        ]);

        $this->realMailManager = $this->app->make('mail.manager');

        Mail::fake();

        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid();
    }

    /**
     * Put the real mailer back for one test.
     *
     * `Mail::fake()` swaps the manager, and everything downstream of it — the
     * `Mailer`, the `MessageSending`/`MessageSent` events, the transport — stops
     * running. That is fine when the question is "was this mailable handed to
     * the mailer", and useless when the question is "does anything in the mail
     * PIPELINE drop mail to a suppressed address". MAIL_MAILER=array in
     * phpunit.xml means restoring it still sends nothing anywhere: the messages
     * land in the array transport, where `assertMessageWasSent()` reads them.
     */
    private function useRealMailer(): void
    {
        Mail::swap($this->realMailManager);
        $this->app->forgetInstance('mailer');
    }

    /**
     * Assert the array transport really accepted a message with this subject for
     * this address — i.e. the whole mail pipeline ran and nothing dropped it.
     */
    private function assertMessageWasSent(string $subjectFragment, string $to): void
    {
        $seen = [];

        foreach ($this->app->make('mailer')->getSymfonyTransport()->messages() as $message) {
            $email = $message->getOriginalMessage();
            $subject = (string) $email->getSubject();
            $recipients = array_map(
                fn ($address) => strtolower($address->getAddress()),
                $email->getTo(),
            );

            $seen[] = $subject . ' -> ' . implode(', ', $recipients);

            if (str_contains($subject, $subjectFragment) && in_array(strtolower($to), $recipients, true)) {
                return;
            }
        }

        $this->fail(implode("\n", array_merge([
            "No message matching \"{$subjectFragment}\" reached {$to}.",
            'Transactional mail must never be filtered by the email suppression list.',
            'What the transport did receive:',
        ], $seen ?: ['(nothing at all)'])));
    }

    // ---------- fixtures ----------

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
            'crm_enabled' => true,
        ]);
    }

    private function makeBroadcast(Masjid $masjid): Broadcast
    {
        return Broadcast::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'title' => 'Snow closure',
            'body' => 'All programs are cancelled today because of the storm.',
            'audience' => 'everyone',
            'status' => Broadcast::STATUS_SENT,
        ]);
    }

    private function makeContact(Masjid $masjid, string $email): Contact
    {
        return Contact::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Test',
            'email' => $email,
        ]);
    }

    /**
     * Run the email channel the way BroadcastDispatcher does — with the tenant
     * BOUND, because the audience resolver relies on that scope and an unbound
     * run would read every organisation's contacts.
     */
    private function deliver(Broadcast $broadcast, Masjid $masjid): \App\Services\Broadcast\ChannelResult
    {
        $tenant = app(TenantContext::class);
        $tenant->set($masjid->id);

        try {
            return app(EmailChannel::class)->deliver($broadcast, $masjid);
        } finally {
            // The public landing runs UNBOUND, like a real web request would.
            $tenant->forgetTenant();
        }
    }

    /** The BroadcastMail queued for one address, so its link can be followed. */
    private function mailFor(string $address): BroadcastMail
    {
        $found = null;

        Mail::assertQueued(BroadcastMail::class, function (BroadcastMail $mail) use ($address, &$found) {
            if ($mail->hasTo($address)) {
                $found = $mail;
            }

            return true;
        });

        $this->assertNotNull($found, "No broadcast email was queued to {$address}.");

        return $found;
    }

    // ---------- 1. the link exists, and it works with no session ----------

    #[Test]
    public function every_broadcast_email_carries_a_working_unsubscribe_link(): void
    {
        $this->makeContact($this->masjid, 'congregant@test.local');
        $broadcast = $this->makeBroadcast($this->masjid);

        $this->deliver($broadcast, $this->masjid);

        $mail = $this->mailFor('congregant@test.local');

        $this->assertNotNull($mail->unsubscribeUrl, 'The mailable carried no unsubscribe URL.');

        // The link is really rendered into the body a congregant reads, not just
        // held on the object.
        $body = $mail->render();
        $this->assertStringContainsString($mail->unsubscribeUrl, $body);
        $this->assertStringContainsString('Unsubscribe from', $body);
        // The sentence that stops a donor reporting a missing receipt as a bug.
        $this->assertStringContainsString('does not affect receipts', $body);

        // And it works with no authentication and no session at all.
        $this->get($mail->unsubscribeUrl)->assertOk();
    }

    #[Test]
    public function the_one_click_header_points_at_a_route_that_accepts_a_post(): void
    {
        $this->makeContact($this->masjid, 'congregant@test.local');

        $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        $mail = $this->mailFor('congregant@test.local');
        $headers = $mail->headers()->text;

        // RFC 8058: the header URL must accept a POST, and the companion header
        // is what tells Gmail/Yahoo this sender supports one-click.
        $this->assertSame('<' . $mail->unsubscribeOneClickUrl . '>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

        // The route answers the provider's POST. Note what this line does NOT
        // prove: Laravel's ValidateCsrfToken short-circuits on
        // runningUnitTests() before it ever looks at the except array, so this
        // passes with or without the exemption that makes it work in
        // production. That guarantee is pinned separately, in
        // `the_unsubscribe_paths_are_exempt_from_csrf_so_a_mailbox_provider_can_reach_them`.
        $this->post($mail->unsubscribeOneClickUrl, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    }

    /**
     * The CSRF exemption, pinned where it is DECLARED and then driven for real.
     *
     * This is the whole of RFC 8058 one-click. Gmail and Yahoo POST
     * `List-Unsubscribe` from their own infrastructure: no cookie of ours, no
     * session, and therefore no token they could ever carry. bootstrap/app.php
     * exempts `unsubscribe/*` for exactly that reason, and the landing page's
     * own "Unsubscribe me" button renders no `@csrf` field on the same grounds.
     *
     * Nothing used to hold that in place. `Illuminate\Foundation\Http\
     * Middleware\ValidateCsrfToken::handle()` returns early when
     * `runningUnitTests()` is true — BEFORE `inExceptArray()` is consulted — so
     * every `$this->post(...)` in this file passes whether or not the exemption
     * exists. Delete those three lines from bootstrap/app.php, or narrow the
     * glob during a tidy-up, and the suite stays green while in production every
     * one-click POST answers 419, Gmail records the unsubscribe as failed and
     * does not retry, no suppression row is written, and the person keeps
     * receiving the newsletter having done exactly what they were told to do.
     * The first signal would be a spam complaint.
     *
     * So this test does not go through the HTTP client at all. It asks the
     * middleware itself, twice:
     *
     *  - structurally, that the declared except list matches every path the
     *    landing answers (and the exact action the blade form posts to), and
     *    does not match paths outside it;
     *  - behaviourally, with the testing short-circuit turned OFF, that a
     *    tokenless POST to the one-click path passes the real `handle()` while a
     *    tokenless POST to a path outside the exemption still throws. That
     *    second assertion is what proves the first half of the pair was not
     *    vacuous.
     */
    #[Test]
    public function the_unsubscribe_paths_are_exempt_from_csrf_so_a_mailbox_provider_can_reach_them(): void
    {
        $middleware = $this->app->make(ValidateCsrfToken::class);

        $isExempt = function (string $path) use ($middleware): bool {
            $method = new \ReflectionMethod($middleware, 'inExceptArray');
            $method->setAccessible(true);

            return (bool) $method->invoke($middleware, Request::create('/' . ltrim($path, '/'), 'POST'));
        };

        $oneClick = route('unsubscribe.store', [
            'masjid_id' => $this->masjid->id,
            'token' => 'a-token',
        ], false);

        $resubscribe = route('unsubscribe.resubscribe', [
            'masjid_id' => $this->masjid->id,
            'token' => 'a-token',
        ], false);

        foreach (['the one-click POST' => $oneClick, 'the re-subscribe POST' => $resubscribe] as $label => $path) {
            $this->assertTrue($isExempt($path), implode("\n", [
                "{$path} is no longer exempt from CSRF verification ({$label}).",
                'A mailbox provider posts this URL with no session and no token, so it now',
                'answers 419 and the opt-out is silently never recorded. See bootstrap/app.php.',
            ]));
        }

        // Not a general exemption: the rest of the application still verifies.
        foreach (['login', 'api/masjid/contacts', 'unsubscribed'] as $path) {
            $this->assertFalse($isExempt($path), "The CSRF exemption has widened to cover {$path}.");
        }

        // The page's own button posts to a path covered by the same glob. It
        // deliberately renders no token field, so if the two ever drift apart
        // every human who clicks "Unsubscribe me" gets a 419.
        $token = app(EmailSuppressionService::class)->token(
            $this->masjid->id,
            'form@test.local',
            null,
            EmailSuppressionService::PURPOSE_UNSUBSCRIBE,
        );

        $page = $this->get(route('unsubscribe.show', [
            'masjid_id' => $this->masjid->id,
            'token' => $token,
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form[^>]+action="([^"]+)"/', $page);
        preg_match('/<form[^>]+action="([^"]+)"/', $page, $matches);

        // `name="_token"` rather than `_token`: the token in the URL is base64url
        // and could in principle contain that substring, which is the kind of
        // once-in-a-blue-moon red this file is being cleaned of.
        $this->assertStringNotContainsString('name="_token"', $page, implode("\n", [
            'The unsubscribe page now renders a CSRF token field.',
            'It must not: the same markup is posted by people with no session, and the',
            'exemption below is what makes the tokenless form work.',
        ]));

        $action = (string) parse_url(html_entity_decode($matches[1]), PHP_URL_PATH);
        $this->assertTrue($isExempt($action), "The confirmation form posts to {$action}, which is not exempt.");

        // ---- and now the middleware itself, with the test short-circuit off ----

        $originalEnv = $this->app['env'];
        $this->app->instance('env', 'production');

        try {
            $session = $this->app['session']->driver();
            $session->start();

            $providerPost = Request::create($oneClick, 'POST');
            $providerPost->setLaravelSession($session);

            $passed = $middleware->handle($providerPost, fn () => new HttpResponse('delivered'));

            $this->assertSame(200, $passed->getStatusCode(), implode("\n", [
                'The real CSRF middleware refused a tokenless POST to the one-click path.',
                'That is what Gmail sends, and a 419 there is an unhonoured opt-out.',
            ]));

            // The control. Same middleware, same missing token, a path the
            // exemption does not cover. If this does NOT throw, the short-circuit
            // is still in force and the assertion above proved nothing.
            $threw = false;

            try {
                $unprotected = Request::create('/login', 'POST');
                $unprotected->setLaravelSession($session);

                $middleware->handle($unprotected, fn () => new HttpResponse('delivered'));
            } catch (TokenMismatchException) {
                $threw = true;
            }

            $this->assertTrue($threw, implode("\n", [
                'CSRF verification did not run at all, so this test cannot see the exemption.',
                'Laravel skips it while the app reports that it is running unit tests; the',
                'env swap above exists to defeat exactly that and has stopped working.',
            ]));
        } finally {
            $this->app->instance('env', $originalEnv);
        }
    }

    #[Test]
    public function a_mailable_built_without_a_link_sends_no_unsubscribe_headers_rather_than_an_empty_one(): void
    {
        // An unconfigured caller must no-op, not emit `List-Unsubscribe: <>`,
        // which a relay would reject and which would fail the whole send.
        $headers = (new BroadcastMail(orgName: 'Org', title: 'T', body: 'B'))->headers()->text;

        $this->assertArrayNotHasKey('List-Unsubscribe', $headers);
        $this->assertArrayNotHasKey('List-Unsubscribe-Post', $headers);
    }

    // ---------- 2. GET renders, POST acts ----------

    #[Test]
    public function a_prefetched_get_shows_a_confirmation_page_and_unsubscribes_nobody(): void
    {
        $this->makeContact($this->masjid, 'congregant@test.local');
        $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        $url = $this->mailFor('congregant@test.local')->unsubscribeUrl;

        // A SafeLinks scanner or a Gmail prefetcher does exactly this.
        $this->get($url)->assertOk()->assertSee('Unsubscribe me');
        $this->get($url)->assertOk();

        $this->assertSame(0, EmailSuppression::withoutMasjidScope()->count());
    }

    #[Test]
    public function posting_the_link_stops_that_organizations_broadcast_emails(): void
    {
        $this->makeContact($this->masjid, 'leaving@test.local');
        $this->makeContact($this->masjid, 'staying@test.local');
        $first = $this->makeBroadcast($this->masjid);

        $this->deliver($first, $this->masjid);

        $this->post($this->mailFor('leaving@test.local')->unsubscribeUrl)
            ->assertOk()
            ->assertSee('will no longer send announcement');

        $this->assertDatabaseHas('email_suppressions', [
            'masjid_id' => $this->masjid->id,
            'email_normalized' => 'leaving@test.local',
            'reason' => EmailSuppression::REASON_UNSUBSCRIBE_LINK,
            'released_at' => null,
        ]);
        // Provenance: which message prompted it.
        $this->assertSame(
            $first->id,
            EmailSuppression::withoutMasjidScope()->first()->broadcast_id,
        );

        // The NEXT broadcast reaches the other person and not this one.
        Mail::fake();
        $result = $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        $this->assertSame(1, $result->targetCount);
        Mail::assertQueued(BroadcastMail::class, 1);
        Mail::assertQueued(
            BroadcastMail::class,
            fn (BroadcastMail $mail) => $mail->hasTo('staying@test.local')
        );
    }

    #[Test]
    public function the_opt_out_is_honoured_where_the_audience_is_resolved_so_nobody_suppressed_is_ever_counted(): void
    {
        $this->makeContact($this->masjid, 'gone@test.local');
        $this->makeContact($this->masjid, 'here@test.local');

        app(EmailSuppressionService::class)->suppress($this->masjid->id, 'gone@test.local');

        $broadcast = $this->makeBroadcast($this->masjid);

        app(TenantContext::class)->set($this->masjid->id);
        $audience = app(\App\Services\Broadcast\BroadcastAudienceResolver::class)->emailAudience($broadcast);
        app(TenantContext::class)->forgetTenant();

        // Not counted, not previewed, not delivered to — the count the admin is
        // shown comes out of this one object.
        $this->assertSame(1, $audience->count());
        $this->assertSame(1, $audience->suppressed);
        $this->assertSame(['here@test.local'], $audience->recipients->pluck('email')->all());
    }

    #[Test]
    public function unsubscribing_twice_updates_one_row_rather_than_adding_a_second(): void
    {
        $this->makeContact($this->masjid, 'twice@test.local');
        $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        $url = $this->mailFor('twice@test.local')->unsubscribeUrl;

        // A second click, or a provider replaying the one-click POST.
        $this->post($url)->assertOk();
        $this->post($url)->assertOk();
        // …and the confirmation page now says so rather than offering the button.
        $this->get($url)->assertOk()->assertSee('already receives no');

        $this->assertSame(1, EmailSuppression::withoutMasjidScope()
            ->where('email_normalized', 'twice@test.local')->count());
    }

    // ---------- 3. it outlives the contact row ----------

    /**
     * A replay is not a new request, and it must not be recorded as one.
     *
     * `store()` is idempotent by design and the docblocks say why: a corporate
     * mail scanner replays the one-click POST by itself, and a person can click
     * the footer link in an older broadcast at any time. Before this was fixed,
     * every one of those rewrote `suppressed_at` and `broadcast_id` to the
     * replay's values.
     *
     * That is the half of the row the class docblock calls the evidence. A
     * congregant unsubscribes on 1 September; a scanner replays on the 20th;
     * a CAN-SPAM complaint asks whether the organisation kept mailing after
     * being asked to stop, and the only record now says the request came on the
     * 20th. Nineteen days moved onto the organisation's side of the line by a
     * request the person never made.
     */
    #[Test]
    public function replaying_the_one_click_post_does_not_move_the_date_the_opt_out_began(): void
    {
        $this->makeContact($this->masjid, 'replayed@test.local');
        $first = $this->makeBroadcast($this->masjid);
        $this->deliver($first, $this->masjid);

        $url = $this->mailFor('replayed@test.local')->unsubscribeOneClickUrl;

        Carbon::setTestNow('2026-09-01 09:00:00');
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

        // Nineteen days of mail later, a scanner replays that same POST…
        Carbon::setTestNow('2026-09-20 11:30:00');
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

        // …and the person also clicks the footer link in a message whose token
        // carries no broadcast id, which used to null the provenance out.
        $anonymous = app(EmailSuppressionService::class)->token(
            $this->masjid->id,
            'replayed@test.local',
            null,
            EmailSuppressionService::PURPOSE_UNSUBSCRIBE,
        );

        $this->post(route('unsubscribe.store', [
            'masjid_id' => $this->masjid->id,
            'token' => $anonymous,
        ]))->assertOk();

        $row = EmailSuppression::withoutMasjidScope()->firstOrFail();

        $this->assertSame(1, EmailSuppression::withoutMasjidScope()->count());
        $this->assertSame(
            '2026-09-01 09:00:00',
            $row->suppressed_at->toDateTimeString(),
            'A replayed one-click POST moved the date the opt-out began.',
        );
        $this->assertSame(
            $first->id,
            $row->broadcast_id,
            'A replayed one-click POST erased which message prompted the opt-out.',
        );
        $this->assertNull($row->released_at);

        // The directory copy carries the same date, not the date of the replay:
        // a badge that disagrees with the evidence is a second version of the
        // fact, and it is the version staff actually read.
        $this->assertSame(
            '2026-09-01 09:00:00',
            Contact::withoutMasjidScope()
                ->where('email', 'replayed@test.local')
                ->firstOrFail()
                ->email_opted_out_at
                ->toDateTimeString(),
        );

        // A genuine NEW opt-out, after the person resumed mail themselves, does
        // start a new date — otherwise this fix would freeze the first one
        // forever and the record would understate when mail was last stopped.
        app(EmailSuppressionService::class)->release($this->masjid->id, 'replayed@test.local');

        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertSame(
            '2026-10-05 08:00:00',
            EmailSuppression::withoutMasjidScope()->firstOrFail()->suppressed_at->toDateTimeString(),
            'Re-suppressing a RELEASED row must record when the new opt-out began.',
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function the_unsubscribe_survives_the_contact_being_force_deleted_and_re_imported(): void
    {
        $contact = $this->makeContact($this->masjid, 'durable@test.local');
        $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        $this->post($this->mailFor('durable@test.local')->unsubscribeUrl)->assertOk();

        // The merge path force-deletes an absorbed contact; the donation importer
        // destroys placeholders; a CSV re-import recreates people. All three of
        // those must leave the opt-out standing.
        $contact->forceDelete();
        $this->makeContact($this->masjid, 'Durable@Test.Local');

        Mail::fake();
        $result = $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        Mail::assertNothingQueued();
        $this->assertSame('skipped', $result->status);
        $this->assertStringContainsString('unsubscribed', $result->note);
    }

    // ---------- 4. transactional mail is untouched ----------

    /**
     * The behavioural half of guarantee 4, driven through the code that
     * actually issues these emails — and through the real mail pipeline.
     *
     * Two things were wrong with proving this by hand-sending the mailables to
     * `Mail::fake()`. The obvious one: a test that sends a mailable itself and
     * then asserts the fake recorded it is asserting its own fixture — no
     * production code decides the outcome, so no production change can fail it.
     * The subtler one: `MailFake` records unconditionally and never fires
     * `MessageSending`, so the specific regression the guarantee names — someone
     * "centralising" the opt-out into a `MessageSending` listener or a mailer
     * decorator, which is the single most damaging change that could be made to
     * this feature — is invisible to any test built on a fake.
     *
     * So this one restores the real mailer (MAIL_MAILER=array, so nothing
     * leaves the process) and drives both senders end to end:
     *
     *  - the tax receipt, from a signature-verified Stripe webhook, exactly as
     *    a real donation produces one (StripeWebhookController::deliverReceipt);
     *  - the registration confirmation, from the public form endpoint a
     *    congregant submits (App\Support\FormNotifier).
     *
     * Both go to an address that has unsubscribed. Both must arrive. If a check
     * appears anywhere in the mail path — a listener, a decorator, a base
     * Mailable, a helpfully "centralised" audience filter — the messages stop
     * reaching the transport and this fails.
     */
    #[Test]
    public function an_unsubscribe_does_not_stop_a_donation_receipt_or_a_registration_confirmation(): void
    {
        $this->useRealMailer();

        $donor = 'donor@test.local';
        $contact = $this->makeContact($this->masjid, $donor);

        app(EmailSuppressionService::class)->suppress($this->masjid->id, $donor);
        $this->assertTrue(app(EmailSuppressionService::class)->isSuppressed($this->masjid->id, $donor));

        // 1. A tax receipt. The donor asked for this by giving money, and the
        //    organisation is obliged to send it whatever their mail preferences.
        $donation = Donation::factory()->create([
            'masjid_id' => $this->masjid->id,
            'contact_id' => $contact->id,
            'fund_id' => $this->makeFund($this->masjid)->id,
            'intended_amount' => 10000,
            'charged_amount' => 10000,
            'status' => 'pending',
        ]);

        $this->postStripeWebhook([
            'id' => 'evt_' . uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_' . uniqid(),
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'payment_intent' => 'pi_' . uniqid(),
                    'client_reference_id' => $donation->uuid,
                    'metadata' => ['donation_uuid' => $donation->uuid],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('donation_receipts', ['donation_id' => $donation->id]);
        $this->assertMessageWasSent('Your donation receipt', $donor);

        // 2. A registration confirmation. Same argument: the person acted.
        $form = $this->makeForm($this->masjid);

        $this->postJson(
            "/api/v1/forms/{$form->id}/responses",
            ['data' => [
                'registrantFirstName' => 'Amina',
                'registrantLastName' => 'Test',
                'registrantEmail' => $donor,
                'registrantPhone' => '336-555-0100',
            ]],
            ['masjid-id' => (string) $this->masjid->id],
        )->assertOk();

        $this->assertMessageWasSent('registration received', $donor);

        // And the opt-out really was in force the whole time — otherwise the two
        // assertions above would be testing nothing at all.
        $this->assertTrue(app(EmailSuppressionService::class)->isSuppressed($this->masjid->id, $donor));
    }

    /**
     * The structural half of the guarantee above.
     *
     * The behavioural test proves today's receipts still go out. This proves the
     * REASON they do — that the suppression is consulted in exactly one place —
     * so the day somebody "centralises" the check into a MessageSending listener
     * or a base Mailable, the suite says so instead of a donor discovering it
     * three weeks later.
     *
     * The scan is over EVERY php file under app/, recursively, and it looks for
     * three spellings of the same mistake, not one. The earlier version globbed
     * four hand-listed directories non-recursively (one of which, app/Events,
     * does not exist) for the word `EmailSuppression`, and keyed its second half
     * on the verb `suppressedAmong(` — so a listener registered in
     * app/Providers, a middleware, or anything calling `isSuppressed(` or
     * reading the mirror column was invisible to both halves. The rule is now
     * stated the other way round: whatever names this feature must be one of the
     * files allowed to.
     */
    #[Test]
    public function the_suppression_is_never_consulted_from_a_mailable_a_job_or_a_mail_hook(): void
    {
        // The only files in the whole application permitted to know that a
        // broadcast email opt-out exists. Adding to this list is a decision:
        // every entry is a place where a transactional email could be dropped.
        $allowed = [
            // The audience for a BROADCAST, and the only place the list decides
            // anything about who is sent to.
            'app/Services/Broadcast/BroadcastAudienceResolver.php',
            // The service that owns the read and the write.
            'app/Services/Broadcast/EmailSuppressionService.php',
            // The model, and the public landing that writes through it.
            'app/Models/EmailSuppression.php',
            'app/Http/Controllers/UnsubscribeController.php',
            // The mirror on the directory row: a display copy, kept true by the
            // model hook and read by the contacts screen. It sends nothing.
            'app/Models/Contact.php',
            // Merge reconciles the survivor's mirror; the crm controller calls it.
            'app/Http/Controllers/AdminDashboard/ContactsController.php',
            // The SENDER side, which is the opposite of a filter: the email
            // channel asks the service to MINT each recipient's unsubscribe link
            // at send time. It never asks whether to send — that answer arrives
            // already applied, in the audience the resolver hands it.
            'app/Services/Broadcast/Channels/EmailChannel.php',
        ];

        // Deliberately NOT the bare verbs `isSuppressed(` / `suppressedAmong(`:
        // SmsConsentService has both for the other channel, and a scan that
        // cannot tell the two apart is one somebody eventually deletes. Every
        // way of reaching this feature passes through one of these three names —
        // the class, the mirror column, or the mirror's accessor.
        $needles = [
            'EmailSuppression',
            'email_opted_out_at',
            'hasEmailOptOut(',
        ];

        $offenders = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            $relative = str_replace(base_path() . '/', '', $file);

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            $source = (string) file_get_contents($file);

            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = $relative . ' (' . $needle . ')';
                    break;
                }
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'Something outside the unsubscribe feature now reads the email suppression list.',
            'That check belongs ONLY in BroadcastAudienceResolver::emailAudience().',
            'Anywhere more central — a Mailable, a job, a MessageSending listener, a mailer',
            'decorator, a middleware — and it swallows donation receipts, annual statements,',
            'registration confirmations, contact-us replies and family sign-in codes.',
            'If the new caller is legitimate, add it to $allowed in this test and say why.',
        ]));

        // …and the allow-list is not stale: every file on it really does still
        // mention the feature. A list that outlives its entries stops being a
        // description of the code and starts being a place to hide things.
        $stale = array_values(array_filter(
            $allowed,
            fn (string $relative) => ! is_file(base_path($relative))
                || ! array_filter(
                    $needles,
                    fn (string $needle) => str_contains((string) file_get_contents(base_path($relative)), $needle),
                ),
        ));

        $this->assertSame([], $stale, 'The allow-list names files that no longer touch the suppression list.');
    }

    // ---------- 5. the link names nobody, and cannot be edited ----------

    #[Test]
    public function a_tampered_or_unsigned_link_is_refused_and_names_no_organization(): void
    {
        $this->makeContact($this->masjid, 'private@test.local');
        $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        $url = $this->mailFor('private@test.local')->unsubscribeUrl;

        // One character, deterministically different from whatever is there, and
        // taken from the MIDDLE of the token — the whole point being that a
        // person cannot reach somebody else by editing the link they were sent.
        //
        // Not the last character. `token()` strips base64 padding, so when the
        // encoded payload is not a multiple of three bytes the final character
        // carries bits that are discarded on decode and several distinct
        // characters map to the same byte. Substituting there produces a
        // "tampered" token that decrypts perfectly on roughly one run in
        // sixteen — a flaky red on the one assertion that guards the link, which
        // trains people to re-run CI instead of reading it.
        $token = last(explode('/', $url));
        $at = intdiv(strlen($token), 2);
        $editedToken = substr_replace($token, $token[$at] === 'A' ? 'B' : 'A', $at, 1);
        $edited = substr($url, 0, strlen($url) - strlen($token)) . $editedToken;

        // The edit proves itself: a substitution that decoded to the same bytes
        // would make the case below pass while testing nothing.
        $this->assertNotSame(
            base64_decode(strtr($token, '-_', '+/')),
            base64_decode(strtr($editedToken, '-_', '+/')),
            'The "edited" token decodes to byte-identical ciphertext, so it is not an edit.',
        );

        foreach ([
            'edited token' => $edited,
            'no token at all' => route('unsubscribe.show', [
                'masjid_id' => $this->masjid->id,
                'token' => 'not-a-token',
            ]),
            'another organisation spliced onto the path' => str_replace(
                '/unsubscribe/' . $this->masjid->id . '/',
                '/unsubscribe/' . $this->makeMasjid()->id . '/',
                $url,
            ),
        ] as $label => $bad) {
            $response = $this->get($bad);

            $response->assertStatus(403);

            $body = $response->getContent();
            $this->assertStringNotContainsString($this->masjid->name, $body, "Leaked the org name via: {$label}");
            $this->assertStringNotContainsString('private@test.local', $body, "Leaked the address via: {$label}");
        }

        // And nothing was written by any of them.
        $this->assertSame(0, EmailSuppression::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_unsubscribe_from_one_organization_does_not_silence_another(): void
    {
        $other = $this->makeMasjid();

        // One human, on both organisations' lists at the same address.
        $this->makeContact($this->masjid, 'shared@test.local');
        $this->makeContact($other, 'shared@test.local');

        $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);
        $this->post($this->mailFor('shared@test.local')->unsubscribeUrl)->assertOk();

        Mail::fake();

        $left = $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);
        $kept = $this->deliver($this->makeBroadcast($other), $other);

        $this->assertSame('skipped', $left->status);
        $this->assertSame(1, $kept->targetCount);
        Mail::assertQueued(BroadcastMail::class, 1);
    }

    // ---------- 6. only the subscriber undoes it ----------

    #[Test]
    public function an_admin_cannot_re_subscribe_somebody_who_unsubscribed(): void
    {
        // There is no admin endpoint, by construction. Nothing under the admin
        // API reaches this controller, and no route outside the public landing
        // resubscribes anybody.
        foreach (Route::getRoutes() as $route) {
            $action = (string) ($route->getActionName() ?? '');

            if (str_contains($action, 'UnsubscribeController')) {
                $this->assertStringStartsWith(
                    'unsubscribe/',
                    $route->uri(),
                    'The unsubscribe controller must only ever be reachable from the public landing.'
                );
            }

            if (str_contains($route->uri(), 'resubscribe')) {
                $this->assertStringStartsWith('unsubscribe/', $route->uri());
            }
        }

        // …and the release verb has exactly one caller in the application.
        $callers = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            $source = (string) file_get_contents($file);

            if (str_contains($source, 'EmailSuppression') && str_contains($source, '->release(')) {
                $callers[] = basename($file);
            }
        }

        $this->assertSame(['UnsubscribeController.php'], $callers, implode("\n", [
            'Something other than the public unsubscribe landing now releases an email opt-out.',
            'Only the subscriber may undo their own unsubscribe, from a link sent to that mailbox —',
            'the same rule SmsConsentService::grant() enforces for a suppressed number.',
        ]));
    }

    /**
     * The honest scope of the purpose-scoped token.
     *
     * It does not pretend to stop a person holding the link from walking both
     * steps by hand — nothing short of an account could. What it guarantees is
     * that the URL in `List-Unsubscribe`, which mailbox providers and scanners
     * POST by themselves, can only ever move in the safe direction.
     */
    #[Test]
    public function the_link_printed_in_an_email_can_stop_mail_but_never_resume_it(): void
    {
        $this->makeContact($this->masjid, 'returning@test.local');
        $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        $url = $this->mailFor('returning@test.local')->unsubscribeUrl;
        $token = last(explode('/', $url));

        $this->post($url)->assertOk();

        // The token printed in the email may only STOP mail. A forwarded message
        // is not authority to resume it.
        $this->post(route('unsubscribe.resubscribe', [
            'masjid_id' => $this->masjid->id,
            'token' => $token,
        ]))->assertStatus(403);

        $this->assertTrue(
            app(EmailSuppressionService::class)->isSuppressed($this->masjid->id, 'returning@test.local')
        );

        // The purpose-scoped token minted onto the landing page does work, and
        // the row is RELEASED rather than deleted — the evidence stays.
        $resubscribe = app(EmailSuppressionService::class)->token(
            $this->masjid->id,
            'returning@test.local',
            null,
            EmailSuppressionService::PURPOSE_RESUBSCRIBE,
        );

        $this->post(route('unsubscribe.resubscribe', [
            'masjid_id' => $this->masjid->id,
            'token' => $resubscribe,
        ]))->assertOk()->assertSee('back on the list');

        $row = EmailSuppression::withoutMasjidScope()->first();

        $this->assertNotNull($row, 'A released suppression must keep its row as evidence.');
        $this->assertNotNull($row->released_at);
        $this->assertFalse(
            app(EmailSuppressionService::class)->isSuppressed($this->masjid->id, 'returning@test.local')
        );
    }

    // ---------- 7. the admin is told ----------

    #[Test]
    public function the_delivery_note_says_how_many_recipients_had_unsubscribed(): void
    {
        $this->makeContact($this->masjid, 'one@test.local');
        $this->makeContact($this->masjid, 'two@test.local');
        $this->makeContact($this->masjid, 'three@test.local');

        $service = app(EmailSuppressionService::class);
        $service->suppress($this->masjid->id, 'two@test.local');
        $service->suppress($this->masjid->id, 'three@test.local');

        $result = $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        $this->assertSame(1, $result->targetCount);
        // Without this sentence "sent to 1" reads as lost data rather than as
        // the organisation's own unsubscribe rate.
        $this->assertStringContainsString('Sent to 1 recipient(s).', $result->note);
        $this->assertStringContainsString('Of 3 contact(s)', $result->note);
        $this->assertStringContainsString('2 have unsubscribed', $result->note);
    }

    #[Test]
    public function the_directory_mirrors_the_opt_out_so_staff_can_see_why_somebody_hears_nothing(): void
    {
        $contact = $this->makeContact($this->masjid, 'Mirrored@Test.Local');

        app(EmailSuppressionService::class)->suppress($this->masjid->id, 'mirrored@test.local');

        $this->assertTrue($contact->fresh()->hasEmailOptOut());

        // The mirror is a DISPLAY copy of a row in another table. Clearing it by
        // hand must change nothing about who is emailed.
        $contact->forceFill(['email_opted_out_at' => null])->save();

        Mail::fake();
        $result = $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        Mail::assertNothingQueued();
        $this->assertSame('skipped', $result->status);
    }

    /**
     * A receiptable fund, so the webhook below really issues a receipt.
     */
    private function makeFund(Masjid $masjid): Fund
    {
        return Fund::create([
            'masjid_id' => $masjid->id,
            'name' => 'General Fund',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Post a Stripe-signed event, signing exactly the way Stripe does — the same
     * harness DonationFlowTest uses. The signature check is REAL; nothing here
     * bypasses the webhook's only gate.
     */
    private function postStripeWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [], [], [],
            [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload,
        );
    }

    /**
     * A free registration form. No fee on purpose: a form with a money leg is
     * not "accepted" until it is paid, and the receipt this test is about is the
     * one an accepted submission produces.
     */
    private function makeForm(Masjid $masjid): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'winter-camp-' . uniqid(),
            'name' => 'Winter camp',
            'schema' => [
                'sections' => [
                    [
                        'id' => 'registrant',
                        'title' => 'Your Information',
                        'fields' => [
                            ['name' => 'registrantFirstName', 'label' => 'First name', 'type' => 'text', 'required' => true],
                            ['name' => 'registrantLastName', 'label' => 'Last name', 'type' => 'text', 'required' => true],
                            ['name' => 'registrantEmail', 'label' => 'Email', 'type' => 'email', 'required' => true],
                            ['name' => 'registrantPhone', 'label' => 'Phone', 'type' => 'tel', 'required' => true],
                        ],
                    ],
                ],
            ],
            'settings' => [
                'identity' => [
                    'name' => ['registrantFirstName', 'registrantLastName'],
                    'email' => 'registrantEmail',
                    'phone' => 'registrantPhone',
                ],
                'fee' => null,
                'notifyEmails' => ['coordinator@test.local'],
            ],
        ]);
    }

    /**
     * The mirror follows the ADDRESS, because the opt-out does.
     *
     * `contacts.email_opted_out_at` is a display copy of a row keyed on the
     * address, and the contact screen renders a badge from it. A copy that can
     * silently disagree with the table that decides is a defect waiting to be
     * trusted, and this one could disagree in both directions the moment an
     * address was edited — the copy was only ever recomputed for the address
     * being suppressed or released.
     *
     * Both directions are here, plus the re-import case, because they fail
     * differently: (a) staff read "unsubscribed" about somebody who is now being
     * emailed, and (b) staff read nothing about somebody who is being silently
     * dropped from every send — which is the exact question the column was added
     * to answer.
     */
    #[Test]
    public function the_mirror_follows_the_address_when_a_contacts_email_is_edited(): void
    {
        $service = app(EmailSuppressionService::class);

        $contact = $this->makeContact($this->masjid, 'bob@test.local');
        $service->suppress($this->masjid->id, 'bob@test.local');

        $this->assertTrue($contact->fresh()->hasEmailOptOut());

        // (a) an admin corrects the address. The opt-out belonged to bob@, which
        //     this person no longer has, so the badge must go with it.
        $contact->email = 'robert@test.local';
        $contact->save();

        $this->assertFalse($service->isSuppressed($this->masjid->id, 'robert@test.local'));
        $this->assertNull(
            $contact->fresh()->email_opted_out_at,
            'The badge outlived the address it described: staff now read "unsubscribed" '
            . 'about somebody every broadcast is being sent to.',
        );

        // (b) another contact is edited ONTO the suppressed address. Nothing
        //     will reach them; the directory has to say so.
        $other = $this->makeContact($this->masjid, 'clean@test.local');
        $this->assertNull($other->fresh()->email_opted_out_at);

        $other->email = 'bob@test.local';
        $other->save();

        $this->assertTrue(
            $other->fresh()->hasEmailOptOut(),
            'A contact moved onto a suppressed address shows no badge, yet is dropped from '
            . 'every send — the exact "why does this person hear nothing" the column answers.',
        );

        // …carrying the date the opt-out BEGAN, not the date of the edit.
        $this->assertTrue(
            $other->fresh()->email_opted_out_at->equalTo(
                $service->suppressedAt($this->masjid->id, 'bob@test.local'),
            ),
        );

        // (c) and a contact re-created at that address by a CSV import — the
        //     path the suppression table exists for — is badged on insert.
        $reimported = $this->makeContact($this->masjid, 'Bob@Test.Local');
        $this->assertTrue($reimported->fresh()->hasEmailOptOut());

        // The mirror still decides nothing. The authority is the other table.
        $reimported->forceFill(['email_opted_out_at' => null])->save();
        $this->assertTrue($service->isSuppressed($this->masjid->id, 'bob@test.local'));
    }

    /**
     * The throttle bounds one LINK, not one caller.
     *
     * The RFC 8058 POST is sent by the mailbox provider's infrastructure, not by
     * the person's device: press Unsubscribe in Gmail and Google posts the URL
     * from Google's egress pool. Keyed on `$request->ip()`, every one-click
     * unsubscribe for every tenant on this deploy therefore collapsed onto a
     * handful of source addresses, and past the limit the route answers 429 —
     * at which point Gmail records the unsubscribe as failed, does not retry,
     * no row is written, and the person keeps receiving the newsletter.
     *
     * So the assertion that matters is the second one: exhausting ONE link must
     * leave a different person's link working from the same source address.
     * Under an IP key it cannot, at any limit.
     */
    #[Test]
    public function hammering_one_unsubscribe_link_does_not_refuse_another_persons(): void
    {
        $this->makeContact($this->masjid, 'hammered@test.local');
        $this->makeContact($this->masjid, 'innocent@test.local');

        $this->deliver($this->makeBroadcast($this->masjid), $this->masjid);

        $hammered = $this->mailFor('hammered@test.local')->unsubscribeOneClickUrl;
        $innocent = $this->mailFor('innocent@test.local')->unsubscribeOneClickUrl;

        // A scanner in a loop on one message, all from one address.
        for ($i = 0; $i < 30; $i++) {
            $this->post($hammered, ['List-Unsubscribe' => 'One-Click'])->assertOk();
        }

        $this->post($hammered, ['List-Unsubscribe' => 'One-Click'])->assertStatus(429);

        // The other person, whose provider happens to share that egress address,
        // can still leave — and really does leave.
        $this->post($innocent, ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertTrue(
            app(EmailSuppressionService::class)->isSuppressed($this->masjid->id, 'innocent@test.local'),
        );
    }

    /** @return array<int, string> */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
