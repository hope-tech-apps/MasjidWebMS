<?php

namespace App\Http\Requests\Admin\Groups;

use App\Support\ScheduledTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;

/**
 * `send_at` on a class story or a scheduled conversation (T-002.4).
 *
 * The value is the SCHOOL's wall clock (`2026-10-05T10:00`), read in the school's
 * zone by App\Support\ScheduledTime, and refused unless it is a real time in the
 * future and no more than `groups.scheduling.max_days_ahead` (30) days ahead. A
 * malformed value is a 422 naming the shape, never a silent "send now": a typo in a
 * date must not publish a story to every family at once.
 */
trait ValidatesSendAt
{
    /** The time the client asked for, or null when it asked for none (or an unreadable one). */
    public function sendAt(): ?CarbonImmutable
    {
        return $this->filled('send_at') ? ScheduledTime::parse($this->input('send_at')) : null;
    }

    /** Add the semantic checks a shape rule cannot make. */
    protected function checkSendAt(Validator $validator): void
    {
        if (! $this->filled('send_at')) {
            return;
        }

        $at = $this->sendAt();

        if ($at === null) {
            $validator->errors()->add('send_at', 'The time must look like 2026-10-05T10:00 (the school\'s own clock).');

            return;
        }

        if (($why = ScheduledTime::refusal($at)) !== null) {
            $validator->errors()->add('send_at', $why);
        }
    }

    /**
     * An explicit `retained_until` may not close before the day the story goes out: the
     * nightly purge deletes on that date alone, so an earlier one would delete a story
     * (and its photos) that never went out.
     */
    protected function checkRetentionAfterSend(Validator $validator): void
    {
        if (! $this->filled('send_at') || ! $this->filled('retained_until')) {
            return;
        }

        $at = $this->sendAt();

        if ($at === null) {
            return;
        }

        try {
            $keptUntil = \Illuminate\Support\Carbon::parse((string) $this->input('retained_until'))->toDateString();
        } catch (\Throwable) {
            return; // the `date` rule reports it
        }

        if ($keptUntil < $at->toDateString()) {
            $validator->errors()->add('retained_until', 'Keep it until the day it goes out or later, or it would be deleted before anybody read it.');
        }
    }
}
