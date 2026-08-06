<?php

namespace App\Services\Flyer;

use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Removes the background from one image by running the u2netp ONNX cutout
 * script that `scripts/provision-cutout.sh` installs (paths in config/flyer.php).
 *
 * The script's contract, which this class is written against:
 *
 *     <python> <script> <in> <out.png> [--max-edge N]
 *     stdout:  ONE json line, {"ok":true,"ms":..,"w":..,"h":..,"coverage":0.377}
 *
 * `coverage` is the opaque fraction of the WRITTEN png, which the script has
 * already cropped to the alpha bounding box — it is not the fraction of the
 * source that survived. See the guard below for why that distinction decides
 * what this class is allowed to reject.
 *
 * Two things this class exists to get right:
 *
 *  1. The image path is user-supplied (an upload's filename ends up in it), so
 *     the process is launched from an argv array and never a shell string.
 *  2. The interesting failure is not a crash. It is a run that exits 0 having
 *     written a fully transparent PNG — indistinguishable from success unless
 *     you look at `coverage`, so it is checked explicitly and reported.
 *
 * Callers get a reason they can show an admin, never an exception to catch.
 * Because a run costs ~3.2s of the droplet's only vCPU, the only caller should
 * be App\Jobs\ProcessFlyerCutout.
 */
class ImageCutout
{
    /**
     * @return array{ok: bool, reason: ?string, meta: array<string, mixed>}
     */
    public function run(string $srcAbsolute, string $dstAbsolute): array
    {
        $config = (array) config('flyer.cutout', []);

        if (! ($config['enabled'] ?? false)) {
            return $this->fail('Background removal is not enabled on this server.');
        }

        $python = (string) ($config['python'] ?? '');
        $script = (string) ($config['script'] ?? '');

        if (! is_file($python) || ! is_executable($python)) {
            return $this->fail("Background removal is not installed on this server (missing {$python}). Run scripts/provision-cutout.sh.");
        }

        if (! is_readable($script)) {
            return $this->fail("Background removal is not installed on this server (missing {$script}). Run scripts/provision-cutout.sh.");
        }

        if (! is_file($srcAbsolute) || ! is_readable($srcAbsolute)) {
            return $this->fail('The source image could not be read.');
        }

        $directory = dirname($dstAbsolute);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return $this->fail("The folder for the finished cutout could not be created ({$directory}).");
        }

        $timeout = max(1, (int) ($config['timeout'] ?? 60));

        // An argv ARRAY, never a command string. Symfony execs the binary
        // directly with these as separate arguments, so an uploaded file named
        // `photo; rm -rf /var/www.jpg` or `$(id).png` is a filename and stays a
        // filename. Passing a single interpolated string here would hand both
        // paths to /bin/sh, and both paths carry user input.
        $process = new Process([
            $python,
            $script,
            $srcAbsolute,
            $dstAbsolute,
            '--max-edge',
            // 1400 is the script's own default and config/flyer.php's; this
            // fallback exists only for a missing config key and must not be a
            // third number — a knob with two values is a knob nobody can reason
            // about from either end.
            (string) max(1, (int) ($config['max_edge'] ?? 1400)),
        ], timeout: $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->fail(
                "Background removal took longer than {$timeout} seconds and was stopped.",
                $dstAbsolute,
                ['retryable' => true],
            );
        }

        if (! $process->isSuccessful()) {
            return $this->fail(
                'Background removal did not finish: ' . $this->lastLine($process->getErrorOutput() ?: $process->getOutput()),
                $dstAbsolute,
                // An exit code is usually the environment (an OOM kill on this
                // box looks exactly like this), not the picture.
                ['retryable' => true, 'exit_code' => $process->getExitCode()],
            );
        }

        $payload = $this->decode($process->getOutput());

        if ($payload === null) {
            return $this->fail('Background removal returned something this app could not read.', $dstAbsolute);
        }

        if (($payload['ok'] ?? false) !== true) {
            $reported = isset($payload['error']) ? (string) $payload['error'] : 'no reason given';

            return $this->fail('Background removal reported a problem: ' . Str::limit($reported, 200), $dstAbsolute);
        }

        if (! is_file($dstAbsolute) || filesize($dstAbsolute) === 0) {
            return $this->fail('Background removal reported success but wrote no image.', $dstAbsolute);
        }

        // The guard that matters, and it only exists at ONE end. The script crops
        // to the alpha bbox BEFORE measuring, so `coverage` is the opaque
        // fraction of the subject's own box — never of the source frame.
        //
        // That kills the upper guard on purpose. Work out what a close-up dish
        // produces, which is the photo this feature exists to handle: the plate
        // runs off all four edges, so its bbox IS the frame and every pixel in it
        // is subject. It reports 1.000 — the same number a model that removed
        // nothing reports. They are not merely close, they are identical, so no
        // threshold can separate them and any ceiling below 1.0 throws away good
        // cutouts of the most common flyer photo. (Measured on the same
        // geometry: a subject with visible background around it lands ~0.78, a
        // half-frame subject ~0.84, a frame-clipped one 1.000.)
        //
        // The low end still means something: a mask that found nothing has no
        // bbox to crop to, so coverage genuinely collapses toward 0, and the
        // resulting PNG is blank rather than merely uncut.
        //
        // A background that was never removed is therefore left to be caught by
        // the person looking at it, which is cheap — the Studio shows the result
        // and the upload can opt out of removal entirely. A wrongly rejected
        // cutout is not cheap: nothing shows the admin what they nearly had.
        $coverage = $payload['coverage'] ?? null;

        if (! is_numeric($coverage)) {
            return $this->fail('Background removal did not report how much of the image it kept, so the result cannot be trusted.', $dstAbsolute);
        }

        $coverage = (float) $coverage;
        $min = (float) ($config['min_coverage'] ?? 0.02);

        // Passes NO path, so the PNG survives. This is a quality judgement about
        // a file the script wrote in full, not a half-written one — and a
        // judgement whose evidence has been deleted is one nobody can check.
        // The destination is deterministic per flyer+source
        // (ProcessFlyerCutout::destinationPath), so a later good run overwrites
        // it rather than piling up, and nothing serves it meanwhile: the row's
        // cutout_status is not 'done'.
        if ($coverage < $min) {
            return $this->fail(
                sprintf('No subject was found in this image — the cutout kept only %.1f%% of it. Try a photo with a clearer subject.', $coverage * 100),
                null,
                ['coverage' => $coverage],
            );
        }

        return [
            'ok' => true,
            'reason' => null,
            'meta' => [
                'ms' => isset($payload['ms']) ? (int) $payload['ms'] : null,
                // Dimensions of the WRITTEN png, which the script trims to the
                // subject's bounding box — not the dimensions of the source.
                // Whatever composes the flyer needs these, not the original's.
                'width' => isset($payload['w']) ? (int) $payload['w'] : null,
                'height' => isset($payload['h']) ? (int) $payload['h'] : null,
                'coverage' => round($coverage, 4),
                // Advisory, never fatal — see the guard above for why this
                // cannot be a rejection. It is the one hint available that a
                // background may not have been removed, so it is carried out to
                // the caller to log rather than thrown away.
                'coverage_high' => $coverage >= (float) ($config['max_coverage'] ?? 1.0),
                'bytes' => filesize($dstAbsolute) ?: null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{ok: false, reason: string, meta: array<string, mixed>}
     */
    private function fail(string $reason, ?string $partial = null, array $meta = []): array
    {
        // Nothing half-written may survive at the destination. The caller only
        // stores the path on success, but a leftover file sitting exactly where
        // a finished cutout belongs is precisely what a retry — or a human
        // poking at the disk — would mistake for one.
        //
        // Callers pass null instead when the script wrote a complete PNG and we
        // are only disputing its quality: that file is the evidence for the
        // dispute and has to outlive it.
        if ($partial !== null && is_file($partial)) {
            @unlink($partial);
        }

        return ['ok' => false, 'reason' => $reason, 'meta' => $meta];
    }

    /**
     * The script contracts to print one JSON line, but a library warning printed
     * to stdout would break a whole-buffer decode. Take the last line that parses
     * as JSON instead of trusting the buffer's shape.
     *
     * @return ?array<string, mixed>
     */
    private function decode(string $stdout): ?array
    {
        $lines = array_filter(array_map('trim', explode("\n", $stdout)), fn (string $line) => $line !== '');

        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /** A Python traceback puts the useful part last, so read from the end. */
    private function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $line) => $line !== ''));

        if ($lines === []) {
            return 'it stopped without saying why';
        }

        return Str::limit((string) end($lines), 200);
    }
}
