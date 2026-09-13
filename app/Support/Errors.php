<?php

namespace App\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Centralized helper for safe error reporting to clients.
 *
 * Security: BEFORE this helper, controllers were returning $e->getMessage()
 * verbatim in 5xx responses — leaking stack-trace-ish details, file paths,
 * SQL errors, and library internals. That gives an attacker a roadmap. This
 * wrapper:
 *
 *   1. Logs the exception for operator visibility — at a level that matches
 *      what actually happened; see below.
 *   2. Returns the raw message ONLY in debug mode (local dev).
 *   3. Returns a generic message in production (no information disclosure).
 *
 * Usage:
 *   } catch (\Throwable $e) {
 *       return response()->json([
 *           'status' => 'error',
 *           'message' => Errors::publicMessage($e),
 *       ], 500);
 *   }
 *
 * ---------------------------------------------------------------------------
 * WHY THE LEVEL IS CHOSEN AND NOT ALWAYS `error`
 * ---------------------------------------------------------------------------
 *
 * This helper is called from the JSON renderer in bootstrap/app.php for EVERY
 * error-shaped JSON response, including 404s — and it logged all of them at
 * ERROR. Measured on production on 2026-09-12: 369 lines for the day, and the
 * twelve most frequent were all internet weather — scanners walking a list of
 * endpoint names (`api/graphql`, `api/proxy`, `api/webhook`, `api/download`)
 * and junk masjid ids against the public mobile API.
 *
 * That matters more here than it would elsewhere, because two of this
 * project's own rules tell whoever is debugging a live incident to read this
 * log first (`.claude/rules/environments.md`, and the axios/Content-Type note
 * that says to check nginx and the app log before theorising). A log in which
 * genuine failures sit one-in-thirty amongst probes is one nobody can read,
 * and "swallowed failure below log level" is already a documented way this
 * platform has lost a real defect.
 *
 * So the level follows the STATUS, and for the 404 family it also follows WHO
 * asked, because those two cases are genuinely different things:
 *
 *   - 5xx, or anything with no status at all: ERROR, exactly as before. The
 *     platform failed. Nothing about this change quiets those.
 *   - 4xx from a stranger: DEBUG. Somebody who is not signed in asked for
 *     something that is missing, or was refused. That is the internet.
 *   - 4xx from a signed-in caller: WARNING. A person with a session followed
 *     a link into a 404 or a 403, and that is usually OUR bug — a stale route,
 *     a tenant scope that stopped matching, a record the UI still lists. It is
 *     not ERROR, because the request was answered correctly; something was
 *     missing, nothing failed.
 *
 * The RESPONSE is identical in every case. This decides what is written down,
 * never what the caller is told.
 */
class Errors
{
    public static function publicMessage(
        Throwable $e,
        string $fallback = 'An error occurred while processing your request.'
    ): string {
        self::record($e);

        return config('app.debug') ? $e->getMessage() : $fallback;
    }

    /**
     * Write the exception down at the level it deserves.
     *
     * The context is deliberately the same on every level, so an operator
     * grepping for a path finds it whichever way the request went, and it
     * deliberately does NOT include the exception's message: a
     * ModelNotFoundException names the model and, on some paths, the id that
     * was looked for.
     */
    private static function record(Throwable $e): void
    {
        $status = match (true) {
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            $e instanceof ModelNotFoundException => 404,
            default => 500,
        };

        $request = request();

        $context = [
            'exception' => $e::class,
            'status' => $status,
            'path' => $request?->path(),
            'method' => $request?->method(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        if ($status >= 500) {
            Log::error($e->getMessage(), $context);

            return;
        }

        // EVERY guard that can be signed in, not `Auth::hasUser()`.
        //
        // The default guard here is `web` (config/auth.php), and almost nothing
        // in this application uses it: staff arrive on a Sanctum bearer token
        // and parents on the custom `family` driver. Asking the default guard
        // alone answers "nobody is signed in" for practically every real
        // caller, which would file our own bugs under the same DEBUG line as a
        // scanner — the exact outcome this method exists to avoid.
        //
        // `hasUser()` answers from a guard's ALREADY-RESOLVED user and never
        // runs a lookup of its own, so asking four guards costs nothing and
        // cannot authenticate anybody as a side effect of writing a log line.
        $guard = null;

        foreach (['sanctum', 'api', 'web'] as $candidate) {
            if (Auth::guard($candidate)->hasUser()) {
                $guard = 'staff';
                break;
            }
        }

        if ($guard === null && Auth::guard('family')->hasUser()) {
            $guard = 'family';
        }

        if ($guard !== null) {
            Log::warning('A signed-in caller was refused or found nothing.', $context + [
                'guard' => $guard,
            ]);

            return;
        }

        Log::debug('Anonymous request refused or found nothing.', $context);
    }
}
