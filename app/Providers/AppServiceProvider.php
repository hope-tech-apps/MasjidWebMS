<?php

namespace App\Providers;

use App\Listeners\ResetTenantContextBetweenJobs;
use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
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
    }

    public function boot(): void
    {
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
        $this->forceHttpsInProduction();
    }

    /**
     * Force HTTPS scheme generation in production so URL::route(), URL::asset(),
     * route('foo') etc. always emit https:// regardless of what proxy headers say.
     * Combined with the SecurityHeaders middleware's HSTS, this prevents mixed-
     * content downgrades.
     */
    private function forceHttpsInProduction(): void
    {
        if ($this->app->environment('production')) {
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
     */
    private function configureRateLimiters(): void
    {
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
        // cannot lock a visitor out of a different masjid's form.
        RateLimiter::for('form-submit', function (Request $request) {
            $key = $request->ip() . '|' . $request->route('form_id');

            return Limit::perHour(8)->by($key)->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many submissions from this connection. Please try again later.',
                ], 429);
            });
        });

        // Public appointment requests (Community vertical, T-021). Same shape as
        // 'form-submit': an unauthenticated DB write, a legitimate person submits
        // once. Keyed by IP AND target organization so flooding one clinic's
        // intake cannot lock a visitor out of another tenant's.
        RateLimiter::for('appointment-request', function (Request $request) {
            $key = $request->ip() . '|' . (string) $request->header('masjid-id');

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

        RateLimiter::for('mobile', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('device', function (Request $request) {
            return Limit::perHour(10)->by($request->ip())->response(function () {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many device registrations from this IP.',
                ], 429);
            });
        });
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
