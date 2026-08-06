<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Background removal ("cutout")
    |--------------------------------------------------------------------------
    |
    | A flyer's subject photo has its background removed by a small U^2-Net
    | (u2netp, 4.4MB) ONNX model, run by a standalone Python venv on the server —
    | not in PHP, not in the browser. `scripts/provision-cutout.sh` installs
    | exactly what these defaults point at.
    |
    | Sizing matters here because the host does not have room to spare: one run
    | measured 354MB peak RSS and ~3.2s wall on the production droplet (2GB RAM,
    | 1 shared vCPU, also running MySQL and PHP-FPM, with 2GB swap). That is why
    | a cutout only ever runs from the queue worker (App\Jobs\ProcessFlyerCutout)
    | — 3.2s of the only vCPU inside a web request would stall the prayer-time
    | API that three masjids' apps poll.
    |
    */

    'cutout' => [

        /*
         * Off by default so an unprovisioned host — a laptop, CI, a fresh
         * droplet — reports "not available" immediately instead of queueing work
         * that can only ever fail. Turn it on in .env per host, after
         * scripts/provision-cutout.sh has verified the install.
         */
        'enabled' => env('FLYER_CUTOUT_ENABLED', false),

        /*
         * The venv interpreter is invoked directly rather than through
         * `source activate`, so running a cutout never needs a shell.
         */
        'python' => env('FLYER_CUTOUT_PYTHON', '/opt/cutout/venv/bin/python'),
        'script' => env('FLYER_CUTOUT_SCRIPT', '/opt/cutout/cutout.py'),

        /*
         * Hard wall-clock ceiling for one run, in seconds — roughly 20x the
         * measured 3.2s. Generous enough that a cold cache or a swapping box
         * still finishes, tight enough that a wedged process cannot hold the
         * single queue worker indefinitely.
         */
        'timeout' => (int) env('FLYER_CUTOUT_TIMEOUT', 60),

        /*
         * Longest edge the image is downscaled to before inference. Matches the
         * script's own default; inference happens at 320x320 regardless, so this
         * only sets how big the mask is resized back up to — which is where the
         * memory goes. Raising it is the quickest way to push the droplet into
         * swap (or OOM) mid-cutout.
         */
        'max_edge' => (int) env('FLYER_CUTOUT_MAX_EDGE', 1400),

        /*
         * How to read the `coverage` the script reports. The failure that
         * actually costs us is not a crash — it is a run that exits 0 having
         * produced a blank mask, which looks like success to everything except
         * this number.
         *
         * The two ends are NOT symmetric, because the script crops to the alpha
         * bounding box before measuring: coverage is the opaque fraction of the
         * subject's own box, never of the source frame.
         *
         *   min — REJECTS. A mask that found no subject has no box to crop to,
         *         so coverage genuinely collapses toward 0 and the PNG is blank.
         *
         *   max — DOES NOT REJECT; it only flags the result in the log. A dish
         *         shot close up runs off all four edges, so its bbox is the whole
         *         frame and it reports 1.000 — the identical number a model that
         *         removed nothing reports. No threshold can tell those apart, and
         *         a ceiling below 1.0 discards good cutouts of the commonest
         *         flyer photo there is. Lower it to widen what gets flagged, not
         *         to filter anything out.
         */
        'min_coverage' => (float) env('FLYER_CUTOUT_MIN_COVERAGE', 0.02),
        'max_coverage' => (float) env('FLYER_CUTOUT_MAX_COVERAGE', 1.0),

        /*
         * Where ProcessFlyerCutout reads the source from and writes the PNG to.
         *
         * `local` is storage/app/private, and it is deliberately NOT the
         * web-exposed `public` disk: a flyer's photo — a janazah portrait above
         * all — must not be readable by anyone who guesses a /storage URL.
         * FlyerCutoutController writes uploads here and serves them back only
         * through its own authenticated image() endpoint. It passes this disk to
         * the job explicitly; this default exists so the two cannot disagree.
         *
         * Whatever it is set to must be a local-filesystem driver: the Python
         * process works on real paths, so a remote driver (which cannot answer
         * ->path()) will not do. `flyers.cutout_path` is relative to this disk.
         */
        'disk' => env('FLYER_CUTOUT_DISK', 'local'),
        'directory' => env('FLYER_CUTOUT_DIRECTORY', 'flyers/cutouts'),

    ],

];
