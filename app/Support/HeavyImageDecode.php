<?php

namespace App\Support;

use App\Exceptions\ImageDecodeBusy;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * One heavy image decode at a time, across the whole box (the image decoding
 * audit, 2026-09-29).
 *
 * Production's GD is the system libgd, whose pixel buffers are allocated
 * OUTSIDE PHP's memory_limit, so no per-process check bounds what several PHP
 * workers decoding at once cost together; the RAM they share is the box's. A
 * decode that may be large (a newsletter picture can be 36 MP, several hundred
 * MB) runs under one global lock instead: a second one waits a few seconds,
 * then is told to try again, rather than both running and waking the OOM
 * killer. Global, not per organisation, because the memory is global.
 *
 * The lock lives in the cache store (the database on production), which every
 * PHP worker shares. It is held at most HOLD_SECONDS, so a worker that dies
 * mid-decode cannot hold it for long.
 */
final class HeavyImageDecode
{
    public const LOCK = 'heavy-image-decode';

    /** Longest a decode may hold the lock; far above a real one (a few seconds). */
    public const HOLD_SECONDS = 60;

    /** How long a second decode waits before the caller is told to try again. */
    public const WAIT_SECONDS = 5;

    /**
     * @template T
     *
     * @param  callable(): T  $decode
     * @return T
     *
     * @throws ImageDecodeBusy when another heavy decode held the lock throughout the wait
     */
    public static function run(callable $decode): mixed
    {
        try {
            return Cache::lock(self::LOCK, self::HOLD_SECONDS)->block(self::WAIT_SECONDS, $decode);
        } catch (LockTimeoutException) {
            throw new ImageDecodeBusy();
        }
    }
}
