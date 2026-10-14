<?php

namespace App\Support;

use Illuminate\Mail\Transport\{ArrayTransport, LogTransport};
use Symfony\Component\Mailer\Transport\{RoundRobinTransport, TransportInterface};

/** Shared school-mail guard: log/array fallbacks cannot be reported as delivery outside local tests. */
final class SchoolMailTransport
{
    public static function unsafe(TransportInterface $transport): bool
    {
        if ($transport instanceof LogTransport || $transport instanceof ArrayTransport) return true;
        if ($transport instanceof RoundRobinTransport) {
            // Symfony exposes no member accessor; include nested failover/custom transports in the check.
            $members = (new \ReflectionProperty(RoundRobinTransport::class, 'transports'))->getValue($transport);
            foreach ($members as $member) if (self::unsafe($member)) return true;
        }
        return false;
    }
}
