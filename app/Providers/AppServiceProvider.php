<?php

namespace App\Providers;

use App\Events\SendMasjidNotificationEvent;
use App\Listeners\SentMasjidNotificationLitener;
use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        // and every BelongsToMasjid model. No Octane here, so singleton == per
        // request. See App\Support\TenantContext.
        $this->app->singleton(\App\Support\TenantContext::class);

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
        Event::listen(
            SendMasjidNotificationEvent::class,
            SentMasjidNotificationLitener::class,
        );

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
