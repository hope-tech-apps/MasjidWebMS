<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve a file off a private disk with HTTP Range support.
 *
 * WHY THIS EXISTS, given `Storage::download()` already serves private files:
 * `download()` answers with the whole file, once, under an `attachment`
 * disposition and with no `Accept-Ranges`. That is exactly right for a
 * photograph or a PDF and useless for video — a <video> element seeks by asking
 * for byte ranges, and a server that cannot answer one forces the browser to
 * buffer the entire file before the scrubber does anything. At the 100MB ceiling
 * this feature carries, that is not a slow experience, it is a broken one.
 *
 * So this is the ranged sibling of `download()`, and it is deliberately NOT a
 * change to it: every photo path in this codebase keeps the response it has
 * always had.
 *
 * ONE CODE PATH, ON PURPOSE. It would be faster to hand a local file to
 * Symfony's BinaryFileResponse and let its `prepare()` do the range arithmetic,
 * and to keep this as the fallback for a disk with no local path. Two paths
 * would mean the one production uses is the one the suite does not, or the
 * reverse; the arithmetic here is thirty lines and is exercised by every test.
 * Neither path avoids PHP holding the worker for the transfer — BinaryFileResponse
 * also reads through PHP unless X-Sendfile is configured, and it is not.
 *
 * NOTHING HERE DECIDES WHO MAY READ THE FILE. Every caller has already
 * re-resolved the ownership chain and re-asked App\Support\GroupAudience; this
 * class is told a path and trusted. See .claude/rules/private-uploads.md.
 */
class PrivateMediaStream
{
    /** How much is read and flushed at a time. 256KB: small enough to stay off the heap, large enough not to syscall-thrash. */
    private const CHUNK = 262144;

    /**
     * Parse an HTTP `Range` header against a known size.
     *
     * @return array{0:int,1:int}|null|false
     *         [start, end] for a satisfiable single range, null for "no range
     *         asked", false for a range this file cannot satisfy (416).
     *
     * Single ranges only. A multipart/byteranges answer is a different response
     * shape, no browser media element asks for one, and half-implementing it is
     * how a server ends up returning the first range under a 206 and calling it
     * done — so a multi-range request is answered as if no range was asked,
     * which is a legal (if unhelpful) response and is never WRONG bytes.
     */
    public static function parseRange(?string $header, int $size): array|null|false
    {
        if ($header === null || $header === '' || $size <= 0) {
            return null;
        }

        if (! preg_match('/^bytes=(.*)$/i', trim($header), $m)) {
            return null;
        }

        $spec = trim($m[1]);

        // Multiple ranges: not answered as a range at all (see above).
        if (str_contains($spec, ',')) {
            return null;
        }

        if (! preg_match('/^(\d*)-(\d*)$/', $spec, $parts)) {
            return false;
        }

        [$rawStart, $rawEnd] = [$parts[1], $parts[2]];

        if ($rawStart === '' && $rawEnd === '') {
            return false;
        }

        if ($rawStart === '') {
            // A suffix range: "the last N bytes". N larger than the file is not
            // an error — it means the whole file.
            $length = (int) $rawEnd;

            if ($length <= 0) {
                return false;
            }

            $start = max(0, $size - $length);
            $end = $size - 1;
        } else {
            $start = (int) $rawStart;

            if ($start >= $size) {
                return false;
            }

            $end = $rawEnd === '' ? $size - 1 : (int) $rawEnd;
            $end = min($end, $size - 1);

            if ($end < $start) {
                return false;
            }
        }

        return [$start, $end];
    }

    /**
     * Answer a request for one private file, honouring `Range`.
     *
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk
     */
    public static function respond(
        $disk,
        string $path,
        string $mimeType,
        string $downloadName,
        Request $request,
    ): Response {
        $size = (int) $disk->size($path);

        $range = self::parseRange($request->headers->get('Range'), $size);

        $headers = [
            'Content-Type' => $mimeType,
            // Inline, unlike every other private-media response in this app, and
            // it is the one thing that makes playback possible: a <video> element
            // is rendering the bytes, not saving them. Safe here for two
            // specific reasons and not in general — the type was SNIFFED from the
            // bytes and constrained to three video containers, and the global
            // `X-Content-Type-Options: nosniff` means the browser will not
            // reinterpret those bytes as anything scriptable.
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $downloadName,
                // ASCII fallback for clients that cannot read the RFC 5987 form.
                preg_replace('/[^\x20-\x7E]/', '_', $downloadName) ?: 'video',
            ),
            // The whole point of this class. Without it a browser will not even
            // offer a scrubber.
            'Accept-Ranges' => 'bytes',
            // No proxy, no CDN and no shared cache may hold one family's video
            // and hand it to the next person who asks for the URL — the same
            // stance every other private-media response takes, and it matters
            // more here because the URL is a signed one that carries no
            // Authorization header of its own.
            'Cache-Control' => 'private, no-store, max-age=0',
        ];

        if ($range === false) {
            // 416, and the Content-Range that says what would have been valid.
            // A seek past the end of a file that was truncated under us is the
            // realistic way to get here; answering 200 with the whole file would
            // make the player believe it had seeked.
            return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                'Content-Range' => 'bytes */' . $size,
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'private, no-store, max-age=0',
            ]);
        }

        [$start, $end] = $range ?? [0, max(0, $size - 1)];
        $length = $size === 0 ? 0 : ($end - $start + 1);

        $headers['Content-Length'] = (string) $length;

        if ($range !== null) {
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }

        $status = $range === null ? Response::HTTP_OK : Response::HTTP_PARTIAL_CONTENT;

        return new StreamedResponse(function () use ($disk, $path, $start, $length): void {
            $stream = $disk->readStream($path);

            if ($stream === false || $stream === null) {
                return;
            }

            try {
                if ($start > 0) {
                    // A non-seekable stream (some remote adapters) is read and
                    // discarded up to the offset rather than silently serving
                    // the file from byte zero under a 206 that claims otherwise.
                    if (fseek($stream, $start) !== 0) {
                        $skipped = 0;
                        while ($skipped < $start && ! feof($stream)) {
                            $chunk = fread($stream, (int) min(self::CHUNK, $start - $skipped));

                            if ($chunk === false || $chunk === '') {
                                break;
                            }

                            $skipped += strlen($chunk);
                        }
                    }
                }

                $remaining = $length;

                while ($remaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, (int) min(self::CHUNK, $remaining));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;

                    $remaining -= strlen($chunk);

                    // Flush per chunk so a long transfer does not accumulate in
                    // PHP's output buffer, and so a client that closes the
                    // connection mid-seek is noticed.
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }

                    flush();
                }
            } finally {
                fclose($stream);
            }
        }, $status, $headers);
    }
}
