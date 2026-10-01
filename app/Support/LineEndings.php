<?php

namespace App\Support;

/**
 * One line ending for text people type: "\n".
 *
 * A browser sends the SAME textarea two ways. A multipart form (how a story or a message
 * with photos is created) carries line breaks as "\r\n"; a JSON or url-encoded body (how
 * an edit is sent) carries "\n". Stored as they arrive, the two never compare equal, so
 * an edit that changed nothing looked like a change: the story was stamped "Edited" and
 * the message got a history row holding the very text it still has (the point's W7-2
 * review, 2026-10-01).
 *
 * Normalised at the request boundary for what is written from now on, and on BOTH sides
 * of every "did the words change" comparison, because rows written before this still hold
 * "\r\n" and are not rewritten.
 */
final class LineEndings
{
    public static function normalise(?string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", (string) $text);
    }

    /**
     * The request's string values under `$keys`, normalised, for `merge()`. A key the
     * request does not carry, or one that is not a string, is left out: validation still
     * sees exactly what was (not) sent.
     *
     * @param array<int,string> $keys
     * @return array<string,string>
     */
    public static function normalised(\Illuminate\Http\Request $request, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $value = $request->input($key);

            if (is_string($value)) {
                $out[$key] = self::normalise($value);
            }
        }

        return $out;
    }
}
