<?php

namespace App\Providers;

use App\Events\SendMasjidNotificationEvent;
use App\Listeners\SentMasjidNotificationLitener;
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
        //
    }

    public function boot(): void
    {
        Event::listen(
            SendMasjidNotificationEvent::class,
            SentMasjidNotificationLitener::class,
        );

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
