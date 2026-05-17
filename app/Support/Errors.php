<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Centralized helper for safe error reporting to clients.
 *
 * Security: BEFORE this helper, controllers were returning $e->getMessage()
 * verbatim in 5xx responses — leaking stack-trace-ish details, file paths,
 * SQL errors, and library internals. That gives an attacker a roadmap. This
 * wrapper:
 *
 *   1. Always logs the full exception to Laravel's logger (operator visibility).
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
 */
class Errors
{
    public static function publicMessage(
        Throwable $e,
        string $fallback = 'An error occurred while processing your request.'
    ): string {
        // Always log internally so ops can debug.
        Log::error($e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return config('app.debug') ? $e->getMessage() : $fallback;
    }
}
