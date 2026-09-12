<?php

namespace App\Providers;

use App\Listeners\ResetTenantContextBetweenJobs;
use App\Models\FormResponse;
use App\Models\User;
use App\Observers\UserObserver;
use App\Support\FormStaffCodes;
use App\Support\TryAgainIn;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request-scoped tenant holder shared by ResolveMasjidTenant middleware
        // and every BelongsToMasjid model. See App\Support\TenantContext.
        //
        // scoped(), NOT singleton(): in a web request the two are identical (no
        // Octane here, and nothing calls forgetScopedInstances() mid-request), but
        // `queue:work` is a long-lived process that resets the container scope
        // before reserving each job — so scoped() is what stops one job's tenant
        // binding from surviving into the next job, which may belong to another
        // masjid. See App\Listeners\ResetTenantContextBetweenJobs for the other
        // half of that fix and the full reasoning.
        $this->app->scoped(\App\Support\TenantContext::class);

        // Shared Stripe SDK client for the CRM donation services. Constructed
        // lazily, so an empty STRIPE_SECRET (keys not added yet) is fine until a
        // call is actually made. Pin the API version so behavior is stable
        // across SDK upgrades. See app/Services/Stripe.
        $this->app->singleton(\Stripe\StripeClient::class, function () {
            return new \Stripe\StripeClient([
                // Pass null (not '') when unset — the SDK rejects an empty-string
                // key at construction, and we want resolution to stay lazy until
                // real keys land.
                'api_key' => config('services.stripe.secret') ?: null,
                'stripe_version' => '2024-06-20',
            ]);
        });

        // The parent portal's "Translate to Arabic" button. Bound to the
        // interface rather than type-hinted concretely in the controller for one
        // reason: the guarantees this feature makes are all statements about how
        // many times the provider was called — a second identical request must
        // cost nothing, two organisations must not share a cache row, a provider
        // failure must be a clean 503 and never a half-translated payload — and a
        // suite that could not swap this binding would have to reach the network
        // to assert any of them. tests/Feature/FamilyTranslationTest.php binds a
        // counting fake here. See App\Services\Translation\Translator.
        //
        // bind(), not singleton(): the implementation is stateless and cheap, and
        // a singleton would carry one request's container-resolved config into
        // the next job on a long-lived `queue:work` process for no benefit.
        $this->app->bind(
            \App\Services\Translation\Translator::class,
            \App\Services\Translation\AnthropicTranslator::class,
        );
    }

    public function boot(): void
    {
        $this->registerFamilyGuard();

        // NOTE ON REGISTRATION: everything under app/Listeners with a typed
        // handle() is ALREADY registered by Laravel's event discovery —
        // Application::configure() calls withEvents() unconditionally, which is
        // invisible from bootstrap/app.php. An Event::listen here therefore
        // registers a SECOND time and the handler runs twice per dispatch, with
        // no error. Do not add one. See .claude/rules/events-listeners.md.
        //
        // The single deliberate exception below is allowlisted in
        // tests/Feature/ListenerRegistrationTest.php, which fails if any other
        // listener acquires a duplicate.

        // Every queued job starts with an UNBOUND tenant. Without this, a job
        // that binds a masjid leaks that binding to the next job the same worker
        // process picks up. Exempts the sync driver, which runs jobs inside the
        // dispatching request. Registered explicitly ON PURPOSE: this is a
        // correctness invariant that must not stop working if discovery is ever
        // disabled, and forgetTenant() is idempotent so the duplicate is a
        // genuine no-op. See App\Listeners\ResetTenantContextBetweenJobs.
        Event::listen(JobProcessing::class, ResetTenantContextBetweenJobs::class);

        // Keep the additive Spatie role mirrored to the legacy `users.type` on
        // every user save. See App\Observers\UserObserver + User::syncRoleFromType().
        User::observe(UserObserver::class);

        $this->responseMacro();
        $this->configureRateLimiters();
        $this->forceHttpsWhenConfigured();
    }

    /**
     * Force HTTPS scheme generation so URL::route(), URL::asset(), route('foo')
     * etc. always emit https:// regardless of what proxy headers say. Combined
     * with the SecurityHeaders middleware's HSTS, this prevents mixed-content
     * downgrades.
     *
     * Keyed off config, NOT the environment NAME. The old `environment('production')`
     * test conflated "is this the live site" with "is this deployment behind TLS",
     * and those are different questions the moment a second TLS-terminated
     * deployment exists: a staging box running APP_ENV=staging would emit http://
     * in every generated URL — Stripe success/return URLs and emailed
     * password-reset links included — because nothing here trusts
     * X-Forwarded-Proto (there is no TrustProxies configuration).
     *
     * `app.force_https` defaults to `APP_ENV === 'production'`, so production
     * behaviour is unchanged when FORCE_HTTPS is unset. See config/app.php.
     */
    private function forceHttpsWhenConfigured(): void
    {
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Named rate limiters. Routes apply them via the `throttle:<name>` middleware.
     *
     *  - "login"   — 5 attempts per minute per email+IP (slow brute-force defense)
     *  - "contact" — 10 messages per hour per IP (spam control on the public contact form)
     *  - "family"  — 60 requests per minute per authenticated CONTACT (T-015c)
     *  - "mobile"  — 60 requests per minute per IP (generous, but bounded)
     *  - "device"  — 10 device registrations per hour per IP (anti-abuse)
     *  - "unsubscribe" — 30 per minute per LINK (plus a coarse per-IP flood
     *                    backstop) on the public unsubscribe landing (T-042c)
     */
    private function configureRateLimiters(): void
    {
        /*
         * The public unsubscribe landing (routes/web.php, T-042c).
         *
         * Deliberately generous, and the reasoning is the same one that puts the
         * inbound SMS webhook OUTSIDE throttle entirely: a rate-limited opt-out is
         * an unhonoured opt-out, and under CAN-SPAM every subsequent message after
         * a request to stop is the organisation's exposure, not a support ticket.
         *
         * ## Keyed on the LINK, not on the caller
         *
         * This route answers two callers that look nothing alike. The GET landing
         * is opened by the person, from their own device. The POST is RFC 8058
         * one-click, and it is issued by the MAILBOX PROVIDER's infrastructure:
         * when somebody presses Unsubscribe in Gmail, Google POSTs this URL from
         * Google's egress pool and the subscriber's IP never appears.
         *
         * Keying on `$request->ip()` therefore pooled every one-click unsubscribe
         * on the platform, for every tenant, onto a handful of provider addresses.
         * Past the limit the route answers 429, Gmail records the one-click as
         * FAILED and does not retry, no `email_suppressions` row is written, and
         * the person keeps receiving the newsletter with nothing anywhere
         * recording that they asked to stop — the exact silent, unhonoured opt-out
         * this limiter exists to avoid, caused by the limiter.
         *
         * So the primary key is the token in the path: one link, one person's
         * opt-out, 30 attempts a minute. That bounds the only abuse this endpoint
         * actually has — a scanner or a bot replaying ONE link — while leaving a
         * provider's shared egress unthrottled, and it cannot make one
         * congregant's unsubscribe fail because a different congregant's mailbox
         * provider was busy.
         *
         * The second limit is a volumetric backstop, an order of magnitude above
         * the old ceiling, so a single source hammering thousands of DISTINCT
         * (and necessarily invalid) tokens still meets a wall. 600/minute is far
         * above anything a real mailbox provider sends on behalf of a deploy this
         * size, which is the property that matters: it must never be the thing
         * that refuses a genuine one-click POST.
         *
         * The refusal is plain text rather than the JSON every other limiter here
         * returns: the caller is a mail client or a browser, and a JSON envelope
         * would render as a wall of braces to a congregant.
         */
        RateLimiter::for('unsubscribe', function (Request $request) {
            $refusal = function () {
                return response(
                    "Too many requests just now. Please wait a minute and open the unsubscribe link again — "
                    . "your request has not been lost.\n",
                    429,
                    ['Content-Type' => 'text/plain; charset=UTF-8'],
                );
            };

            // Every route in the group carries {token}; the IP fallback is for a
            // caller that somehow reaches the limiter without one, so a missing
            // parameter can never mean "unlimited".
            $token = $request->route('token');
            $link = is_string($token) && $token !== '' ? $token : (string) $request->ip();

            return [
                Limit::perMinute(30)->by('unsubscribe-link:' . $link)->response($refusal),
                Limit::perMinute(600)->by('unsubscribe-ip:' . $request->ip())->response($refusal),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email')) . '|' . $request->ip();
            return Limit::perMinute(5)->by($key)->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many login attempts. Try again in a minute.',
                ], 429);
            });
        });

        RateLimiter::for('contact', function (Request $request) {
            return Limit::perHour(10)->by($request->ip())->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You\'ve sent too many messages. Try again later.',
                ], 429);
            });
        });

        // Public form submissions. Tighter than 'contact' because an RSVP or camp
        // signup form is a materially bigger abuse target than a contact form, and a
        // legitimate person submits once. Keyed by IP AND form so flooding one form
        // cannot lock a visitor out of a different masjid's form. The hourly figure
        // is config('forms.submit_per_hour') — 8, as it always was, unless an
        // operator raises it for an event.
        //
        // A request carrying a staff credential (a code typed at the gate, or the
        // token one was exchanged for — App\Support\FormStaffCodes) is limited per
        // CODE instead, keyed by the code's HMAC digest: every staff phone at the
        // venue shares one NAT address, and a walk-up every few minutes is a normal
        // gate. A junk credential cannot be used to get round the public limit: a
        // request carrying one either presents a good credential for the form or
        // writes nothing.
        //
        // Every staff request but a VERIFIED token also meets a per-connection flood
        // guard, listed FIRST, so a request it turns away never spends a holder's
        // hourly allowance. A verified token (genuine, live, from the phone its
        // code is bound to: FormStaffCodes::throttleBucket()) never meets it. Junk
        // codes sent from the venue wifi fill that guard, and they would otherwise
        // stop every staff phone behind the same address from recording cash. A typed
        // code is charged to its holder's hour only when it would be accepted from
        // that phone; every refused one meets the guard alone, so knowing a code is
        // not a way to spend its holder's hour, and the limit headers are the same
        // for every refusal.
        //
        // Each 429 carries the throttle's Retry-After and X-RateLimit-Reset, and says
        // how long in words: the per-code window runs an hour from its first entry.
        RateLimiter::for('form-submit', function (Request $request) {
            $formId = $request->route('form_id');
            $staff = FormStaffCodes::throttleBucket($request, $formId);

            if ($staff === null) {
                $key = $request->ip() . '|' . $formId;

                return Limit::perHour(max(1, (int) config('forms.submit_per_hour', 8)))->by($key)->response(function () {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Too many submissions from this connection. Please try again later.',
                    ], 429);
                });
            }

            $tooMany = fn (Request $request, array $headers) => response()->json([
                'status' => 'error',
                'message' => 'Too many staff entries. ' . TryAgainIn::fromHeaders($headers),
            ], 429, $headers);

            // The throttle checks and charges these in order, stopping at the first
            // that is full.
            $limits = [];

            if (! $staff['verified']) {
                $limits[] = Limit::perMinute(max(1, (int) config('forms.code_per_minute_per_ip', 30)))
                    ->by('form-code-ip:' . $request->ip() . '|' . $formId)
                    ->response($tooMany);
            }

            // None for a token that did not check out: it writes nothing, and is no
            // holder's to charge.
            if ($staff['bucket'] !== null) {
                $limits[] = Limit::perHour(max(1, (int) config('forms.code_per_hour', 60)))
                    ->by('form-code:' . $formId . '|' . $staff['bucket'])
                    ->response($tooMany);
            }

            return $limits;
        });

        // The staff-code exchange (POST /forms/{id}/staff-session). A flood guard
        // only: the brute-force defence is FormStaffCodes' failure limiter. Keyed
        // per DEVICE as well as per connection, as that limiter is. Every phone at
        // the venue shares one address, and a guard keyed by the address alone is
        // one a stranger fills with junk codes to stop every staff phone behind it
        // swapping its code for a token. A guesser who rotates device ids gets past
        // this guard and meets the failure limiter's form-wide ceiling instead.
        // Hashed only to bound the key's length; a non-scalar device_id is refused
        // inside, so it is merely counted here.
        RateLimiter::for('form-staff-session', function (Request $request) {
            $device = $request->input('device_id');
            $key = 'form-staff-session:' . $request->route('form_id') . '|'
                . hash('sha256', $request->ip() . '|' . (is_scalar($device) ? (string) $device : ''));

            return Limit::perMinute(max(1, (int) config('forms.code_per_minute_per_ip', 30)))->by($key)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'status' => 'error',
                    'message' => 'Too many staff code attempts. ' . TryAgainIn::fromHeaders($headers),
                ], 429, $headers));
        });

        // The card return page (GET /form-responses/{uuid}) and "Return to payment"
        // (POST /form-responses/{uuid}/checkout). Keyed by the registration's uuid,
        // NOT the connection (festival brief, blocker 3): every phone at the venue
        // shares one address, and a per-address bucket would serve a handful of payers
        // an hour. The return page polls while the webhook lands, so 30 reads per
        // registration per hour is two full rounds of polling.
        //
        // A uuid that names no registration at the header's masjid
        // (FormResponse::isPaymentHandle(), one query on the unique index) meets a
        // per-connection flood guard INSTEAD, set far above any queue of real payers: it
        // only stops one client hammering the lookup with made-up uuids. A real
        // registration never meets that guard, so the junk that fills it (a stranger on
        // the venue wifi, or on a carrier's shared address) never stops a payer behind the
        // same address, and a request it turns away never spends a registration's own
        // allowance. Each 429 carries the throttle's Retry-After and says how long in
        // words: a registration's window runs an hour from its first request.
        RateLimiter::for('form-status', function (Request $request) {
            $tooMany = fn (Request $request, array $headers) => response()->json([
                'status' => 'error',
                'message' => 'Too many requests. ' . TryAgainIn::fromHeaders($headers),
            ], 429, $headers);

            return FormResponse::isPaymentHandle((string) $request->route('uuid'), (int) $request->header('masjid-id'))
                ? Limit::perHour(30)->by('form-status:' . strtolower((string) $request->route('uuid')))->response($tooMany)
                : Limit::perMinute(300)->by('form-status-ip:' . $request->ip())->response($tooMany);
        });

        // Each "Return to payment" may open a Stripe page on the organisation's
        // account, so it is tighter than the status read, and still per registration,
        // with made-up uuids kept to a per-connection guard as above.
        RateLimiter::for('form-checkout', function (Request $request) {
            $tooMany = fn (Request $request, array $headers) => response()->json([
                'status' => 'error',
                'message' => 'Too many payment attempts. ' . TryAgainIn::fromHeaders($headers),
            ], 429, $headers);

            return FormResponse::isPaymentHandle((string) $request->route('uuid'), (int) $request->header('masjid-id'))
                ? Limit::perHour(20)->by('form-checkout:' . strtolower((string) $request->route('uuid')))->response($tooMany)
                : Limit::perHour(120)->by('form-checkout-ip:' . $request->ip() . '|' . (string) $request->header('masjid-id'))->response($tooMany);
        });

        // Public appointment requests (Community vertical, T-021). Same shape as
        // 'form-submit': an unauthenticated DB write, a legitimate person submits
        // once. Keyed by IP AND target organization so flooding one clinic's
        // intake cannot lock a visitor out of another tenant's.
        //
        // The tenant half of the key is the header cast THE SAME WAY the
        // controller casts it (AppointmentRequestsController::store does
        // `(int) $request->header('masjid-id')`), and that identity is the whole
        // control. Keyed on the raw string, `7`, `07`, `007`, `+7` and `7x` are
        // one tenant to the controller — every one of them passes
        // `Masjid::whereKey(7)->exists()` and writes a row into masjid 7's
        // triage queue — but five different buckets to the limiter, so a single
        // IP had an unbounded supply of fresh allowances against one clinic and
        // the cap was decorative. Any future change to how the controller
        // resolves the tenant has to be mirrored here or the same hole reopens.
        RateLimiter::for('appointment-request', function (Request $request) {
            $key = $request->ip() . '|' . (int) $request->header('masjid-id');

            return Limit::perHour(8)->by($key)->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many requests from this connection. Please try again later.',
                ], 429);
            });
        });

        // Public contact-us intake. The OLDEST unauthenticated DB write in the
        // app and, until 2026-08-11, the only one with no limiter at all —
        // routes/api_v1.php even claimed in a comment that form-submit was the
        // only public write, which was wrong. One request creates up to four
        // rows (MobileAppUser, ContactUsAccount, ContactUsReason, and the
        // message), and `contact_us_reasons` is a GLOBAL table joined into every
        // tenant's admin inbox, so an unbounded loop here is both storage growth
        // and attacker-chosen text in every organisation's UI.
        //
        // Same shape and allowance as 'appointment-request': a real person
        // contacts a masjid a handful of times, and the key includes the target
        // organization so flooding one masjid cannot lock a visitor out of
        // another's contact form.
        RateLimiter::for('contact-us', function (Request $request) {
            // The V1 endpoint names its tenant in the header, the mobile one in
            // the route — take whichever is present so both are keyed per-org.
            $tenant = (string) ($request->route('masjid_id') ?? $request->header('masjid-id'));

            return Limit::perHour(8)->by($request->ip() . '|' . $tenant)->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many requests from this connection. Please try again later.',
                ], 429);
            });
        });

        // Public registration intake (T-006c) — an unauthenticated DB write
        // that also opens a Stripe Checkout Session, so it is the tightest of
        // the public writes. Keyed by IP AND target organization so flooding
        // one masjid's signup cannot lock a visitor out of another's.
        RateLimiter::for('registration-intake', function (Request $request) {
            $key = $request->ip() . '|' . (string) $request->header('masjid-id');

            return Limit::perHour(8)->by($key)->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many registration attempts from this connection. Please try again later.',
                ], 429);
            });
        });

        // Price quotes write NOTHING, and a registrant legitimately re-prices
        // while comparing fee plans — looser than the intake limit above, still
        // bounded so the endpoint cannot be used to enumerate offerings.
        RateLimiter::for('registration-quote', function (Request $request) {
            $key = $request->ip() . '|' . (string) $request->header('masjid-id');

            return Limit::perHour(60)->by($key)->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many requests from this connection. Please try again later.',
                ], 429);
            });
        });

        // Public zakat calculator (T-031). Writes nothing, so it takes the
        // looser 'registration-quote' allowance rather than the intake one — a
        // donor legitimately recalculates many times while assembling their
        // figures, and there is no row at the end of it to abuse. Still bounded:
        // the endpoint should not become free compute. Keyed by IP AND target
        // organization for the same reason as every limiter above.
        RateLimiter::for('zakat-calculator', function (Request $request) {
            $key = $request->ip() . '|' . (string) $request->header('masjid-id');

            return Limit::perHour(60)->by($key)->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many requests from this connection. Please try again later.',
                ], 429);
            });
        });

        // Public Jummah-lunch order (money path — opens a Stripe Checkout Session
        // for an online order). The tightest public-write allowance, keyed by IP
        // AND target organization so flooding one masjid's ordering cannot lock a
        // visitor out of another's.
        RateLimiter::for('lunch-order', function (Request $request) {
            $key = $request->ip() . '|' . (string) $request->header('masjid-id');

            return Limit::perHour(12)->by($key)->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many order attempts from this connection. Please try again later.',
                ], 429);
            });
        });

        // The lunch menu + order-status reads write nothing, so they take the
        // looser allowance — a hungry visitor re-loads the menu while deciding.
        RateLimiter::for('lunch-menu', function (Request $request) {
            $key = $request->ip() . '|' . (string) $request->header('masjid-id');

            return Limit::perHour(60)->by($key)->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many requests from this connection. Please try again later.',
                ], 429);
            });
        });

        // The parent/guardian realm (T-015c, routes/family.php). Unlike every
        // limiter above it is applied to an AUTHENTICATED tree, so it is keyed
        // on the contact rather than the IP: a whole household — or a whole
        // school run sharing one mosque wifi NAT — must not be able to lock
        // each other out, and a stolen token must not be able to hide behind a
        // fresh IP. `throttle` sorts AFTER `auth` in Laravel's middleware
        // priority, so the principal is always resolved by the time this runs;
        // the IP fallback covers the refusal paths, where there is no contact.
        RateLimiter::for('family', function (Request $request) {
            $principal = $request->user();

            $key = $principal instanceof \App\Models\Contact
                ? 'contact:' . $principal->getKey()
                : 'ip:' . $request->ip();

            return Limit::perMinute(60)->by($key)->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many requests. Please try again in a minute.',
                ], 429);
            });
        });

        // The two UNAUTHENTICATED family endpoints (T-015d): request a sign-in
        // code, and exchange one for a token. Both return TWO limits, because
        // the two abuse shapes are different and either alone leaves the other
        // open:
        //
        //   - per submitted ADDRESS  — stops one family's mailbox being flooded
        //     with codes, and stops a distributed attacker grinding one account
        //     from many IPs.
        //   - per IP                 — stops one host walking a list of
        //     addresses. Set higher than the address limit so a household or a
        //     school-run NAT does not lock itself out.
        //
        // THE KEY IS ALWAYS THE VALUE THE CALLER SUBMITTED, never one we looked
        // up. That is what keeps a 429 from being an existence oracle: an
        // address that names a real parent and one that names nobody are
        // throttled by the identical rule, so the response to the sixth attempt
        // is the same either way. Hashed into the key so a cache dump is not a
        // list of the families who tried to sign in, and salted with the masjid
        // so two tenants never share a bucket.
        RateLimiter::for('family-login', function (Request $request) {
            return [
                Limit::perHour((int) config('family.login.requests_per_hour_per_address', 5))
                    ->by($this->familyLoginKey($request, 'addr'))
                    ->response($this->tooManyLoginAttempts()),
                Limit::perHour((int) config('family.login.requests_per_hour_per_ip', 20))
                    ->by('family-login-ip:' . $request->ip())
                    ->response($this->tooManyLoginAttempts()),
            ];
        });

        // Verification. Layered ON TOP of `contact_login_codes.attempts`, which
        // is the per-CODE lockout: the column stops one code being ground down
        // and survives a cache flush because it is a fact about a record; these
        // stop an attacker cycling fresh codes, which a per-code counter cannot
        // see. Both are required — .claude/rules is explicit that a limiter in
        // the cache is not a durable control.
        RateLimiter::for('family-verify', function (Request $request) {
            return [
                Limit::perHour((int) config('family.login.verifications_per_hour_per_address', 10))
                    ->by($this->familyLoginKey($request, 'verify'))
                    ->response($this->tooManyLoginAttempts()),
                Limit::perHour((int) config('family.login.verifications_per_hour_per_ip', 40))
                    ->by('family-verify-ip:' . $request->ip())
                    ->response($this->tooManyLoginAttempts()),
            ];
        });

        /*
         * A parent OPENING a conversation.
         *
         * Replying is deliberately not limited here — a parent mid-conversation
         * should never be told to slow down. Opening is different: it is the one
         * verb that creates a new object a teacher has to triage, so a stuck
         * client or a frustrated parent tapping Send repeatedly must not manufacture
         * twenty threads. Keyed on the authenticated CONTACT, not the IP: a family
         * sharing a phone is one parent, and a household behind one NAT address is
         * still several.
         */
        RateLimiter::for('family-thread', function (Request $request) {
            $contactId = $request->user()?->id ?? 'guest';

            return Limit::perHour((int) config('family.threads.opened_per_hour', 6))
                ->by('family-thread:' . $contactId)
                ->response(function () {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'You have started several conversations already. '
                            . 'Please continue one of them, or try again later.',
                    ], 429);
                });
        });

        /*
         * "Translate to Arabic" in the parent portal (routes/family.php).
         *
         * THE ONLY ENDPOINT IN THIS APPLICATION WHERE AN AUTHENTICATED PARENT
         * SPENDS MONEY. Everything else a family can reach reads rows we already
         * hold; a translation that misses the cache is a paid call to Anthropic,
         * so the realm's 60/min is far too generous a ceiling for it and this
         * limiter narrows the same contact to twenty. The route carries BOTH.
         *
         * Twenty a minute is deliberately not stingy — a parent working down a
         * class story, tapping translate on each paragraph, must never be told
         * to slow down — but it bounds a stolen token, or a client stuck in a
         * retry loop, to something a school's bill survives.
         *
         * Keyed on the CONTACT for the reason the `family` limiter is: a whole
         * household on one phone is one parent, and a school run sharing the
         * mosque's wifi is not one attacker. The IP fallback covers the refusal
         * paths, where there is no principal to key on. The number is a literal
         * rather than a config key on purpose — config/translation.php holds the
         * per-request ceilings, which is what an operator reaches for in an
         * incident; a second dial that also caps spend would make "why is this
         * still expensive?" a two-file question.
         */
        RateLimiter::for('family-translate', function (Request $request) {
            $principal = $request->user();

            $key = $principal instanceof \App\Models\Contact
                ? 'contact:' . $principal->getKey()
                : 'ip:' . $request->ip();

            return Limit::perMinute(20)
                ->by('family-translate:' . $key)
                ->response(function () {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'You have asked for a lot of translations at once. '
                            . 'Please wait a minute and try again.',
                    ], 429);
                });
        });

        /*
         * App member sign-up / sign-in, the self-serve twin of `family-login`.
         *
         * Same ceilings as the family realm and for the same reasons, but this
         * door is reached by strictly more people — anybody who installs a
         * tenant's app — so it has no business being looser. The bucket helper
         * is shared deliberately: it (int)-casts the masjid and hashes the
         * address, and that normalisation was a fix for a real bypass. A second
         * hand-rolled key would be a second chance to reintroduce it.
         */
        RateLimiter::for('member-login', function (Request $request) {
            return [
                Limit::perHour((int) config('member.signup.requests_per_hour_per_address', 5))
                    ->by($this->familyLoginKey($request, 'member-addr'))
                    ->response($this->tooManyLoginAttempts()),
                Limit::perHour((int) config('member.signup.requests_per_hour_per_ip', 20))
                    ->by('member-login-ip:' . $request->ip())
                    ->response($this->tooManyLoginAttempts()),
            ];
        });

        RateLimiter::for('member-verify', function (Request $request) {
            return [
                Limit::perHour((int) config('member.signup.verifications_per_hour_per_address', 10))
                    ->by($this->familyLoginKey($request, 'member-verify'))
                    ->response($this->tooManyLoginAttempts()),
                Limit::perHour((int) config('member.signup.verifications_per_hour_per_ip', 40))
                    ->by('member-verify-ip:' . $request->ip())
                    ->response($this->tooManyLoginAttempts()),
            ];
        });

        RateLimiter::for('mobile', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // (the family guard driver is registered at the top of boot())

        RateLimiter::for('device', function (Request $request) {
            return Limit::perHour(10)->by($request->ip())->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many device registrations from this IP.',
                ], 429);
            });
        });
    }

    /**
     * The throttle bucket for one submitted family sign-in address, in one
     * organisation.
     *
     * Hashed, and salted with the masjid: the raw value is a family's email
     * address, and a cache key is a place it would sit in plaintext in Redis or
     * the cache table for an hour. The masjid comes from the ROUTE, the same
     * place `ResolveFamilyGuestTenant` reads it, so one tenant's traffic can
     * never consume another's allowance.
     */
    /**
     * The `family` guard, built with ITS OWN expiration.
     *
     * ## Why this exists at all
     *
     * Sanctum registers one driver and hands every guard the single global
     * `config('sanctum.expiration')`, enforced against the token's `created_at`
     * inside `Laravel\Sanctum\Guard`. A per-token `expires_at` can therefore only
     * ever SHORTEN a token; it can never extend one past the global. That left
     * two bad options and one good one:
     *
     *   - raise the global -> staff sessions lengthen too, which
     *     .claude/rules/auth-permissions.md forbids;
     *   - leave parents at 8 hours -> they re-authenticate on essentially every
     *     visit, which is what "parent login is hard" actually was;
     *   - give the family guard its own driver. This.
     *
     * It is a copy of SanctumServiceProvider::createGuard with one value
     * changed. Nothing else about the guard differs: same RequestGuard, same
     * Sanctum Guard, same `contacts` provider pinned in config/auth.php — so the
     * provider check that keeps a parent's token off the admin API still runs
     * exactly as before.
     *
     * The `auth.guards.family.driver` config points here. If that is ever set
     * back to 'sanctum', parents silently return to 8 hours — hence the test.
     */
    private function registerFamilyGuard(): void
    {
        Auth::extend('sanctum-family', function ($app, $name, array $config) {
            return new RequestGuard(
                new \Laravel\Sanctum\Guard(
                    $app['auth'],
                    (int) config('family.session.expiration_minutes', 43200),
                    $config['provider'] ?? null,
                    config('sanctum.last_used_at', true)
                ),
                $app['request'],
                $app['auth']->createUserProvider($config['provider'] ?? null)
            );
        });
    }

    private function familyLoginKey(Request $request, string $prefix): string
    {
        // NORMALISE BOTH HALVES. This bucket is the only thing standing between a
        // stranger who knows a parent's address and an unbounded stream of
        // "your sign-in code" emails from their child's school, and it was
        // bypassable twice over:
        //
        //  - The masjid was taken as the RAW route string, while
        //    ResolveFamilyGuestTenant binds the tenant with `(int)`. So "1",
        //    "01", "001", "1abc" and "1.0" all resolved to masjid 1 and each
        //    minted a FRESH bucket — measured: 12 codes issued to one parent
        //    against a documented ceiling of 5. Cast it the same way the
        //    middleware does, so one tenant is one bucket. `routes/family.php`
        //    now also constrains the segment with whereNumber(), which is the
        //    structural half of the same fix; this is the half that does not
        //    depend on a route file staying right.
        //
        //  - `(string)` on an array raises "Array to string conversion", which
        //    Laravel escalates to an ErrorException — a 500 from an
        //    unauthenticated endpoint, raised INSIDE the limiter, i.e. before
        //    the counter increments and before FormRequest validation exists to
        //    reject it. Unmetered error-log noise on the sign-in door.
        //
        // Non-scalar input collapses to the empty string rather than throwing:
        // the request is going to fail validation a moment later anyway, and the
        // limiter's job is to count it, not to judge it.
        $submitted = $request->input('email');
        $email = is_scalar($submitted) ? strtolower(trim((string) $submitted)) : '';

        $masjidId = (int) $request->route('masjid_id');

        return $prefix . ':' . hash('sha256', $masjidId . '|' . $email);
    }

    /** One 429 body for both family sign-in limiters — see the note above. */
    private function tooManyLoginAttempts(): callable
    {
        return function () {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many sign-in attempts. Please try again later.',
            ], 429);
        };
    }

    private function responseMacro(): void
    {
        Response::macro('api', function ($status = 200, $message = '', $data = [], $headers = []) {
            $result = [
                'status' => $status === 200 ? 'success' : 'error',
                'message' => $message,
                'data' => $data,
            ];
            return response()->json(array_filter($result), $status, $headers);
        });
    }
}
