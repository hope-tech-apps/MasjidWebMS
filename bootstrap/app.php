<?php

use App\Http\Middleware\EnsureCrmEnabled;
use App\Http\Middleware\ResolveMasjidTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SuperAdminMiddleware;
use App\Http\Middleware\UserAdminMiddleware;
use App\Support\Errors;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::prefix('api')
                ->middleware('api')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Security headers on every response (web + api).
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'super' => SuperAdminMiddleware::class,
            'admin' => UserAdminMiddleware::class,
            // Binds TenantContext to a MasjidAdmin's masjid; no-op for SuperAdmin
            // and never applied to the public mobile routes. See routes/admin.php.
            'tenant' => ResolveMasjidTenant::class,
            // Per-masjid CRM feature gate. Applied ONLY to the CRM route group
            // (contacts/funds/donations/connect); 403s unless masjids.crm_enabled
            // is true. Runs after `tenant`, so the target masjid is resolved. The
            // SuperAdmin crm-access toggle and the 2FA endpoints are NOT gated.
            'crm' => EnsureCrmEnabled::class,
            // Additive spatie/laravel-permission aliases — applied ONLY to the new
            // CRM endpoints (see routes/admin.php). Its UnauthorizedException is an
            // HttpException(403), so the JSON renderer below returns a clean 403.
            // The legacy `admin`/`super` type checks above are untouched.
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // Don't include sensitive context in default reports.
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'old_password',
            'service_role_key',
            'supabase_service_key',
            'onesignal_rest_api_key',
        ]);

        // JSON renderer for API + AJAX requests — preserves the legacy envelope
        // ({status, message}) the Vue admin and mobile clients expect, but
        // never leaks $e->getMessage() in production.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {

                // Rate limiter, BaseFormRequest, and other "I have my own response"
                // exceptions carry the response object inside them. Return it
                // as-is so we preserve 429 / 422 / etc. with their correct bodies.
                if ($e instanceof HttpResponseException) {
                    return $e->getResponse();
                }

                if ($e instanceof AuthenticationException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthenticated.',
                    ], Response::HTTP_UNAUTHORIZED);
                }

                if ($e instanceof RouteNotFoundException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Route not found.',
                    ], Response::HTTP_NOT_FOUND);
                }

                // HTTP-aware exceptions (404, 403, 422 thrown manually, etc.) —
                // preserve their status code, sanitize their message.
                if ($e instanceof HttpExceptionInterface) {
                    return response()->json([
                        'status' => 'error',
                        'message' => Errors::publicMessage($e, 'Request failed.'),
                    ], $e->getStatusCode());
                }

                // Anything else — generic 500 with sanitized message.
                return response()->json([
                    'status' => 'error',
                    'message' => Errors::publicMessage($e),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        });

    })->create();
