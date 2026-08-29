<?php

namespace App\Support\Canary;

/**
 * How a probe reaches the application.
 *
 * Two implementations, and the difference is not cosmetic:
 *
 *  - HttpTransport   crosses the real edge (proxy, TLS, cache, load balancer).
 *                    A header can be stripped or rewritten out there with no
 *                    code change, so this is what production runs.
 *  - KernelTransport dispatches in-process through the same HTTP kernel. This
 *                    is what the test suite runs, so a canary test probes the
 *                    test application and never the internet.
 */
interface ProbeTransport
{
    public function send(Probe $probe): ProbeResult;

    /** Short name for the report. */
    public function name(): string;
}
