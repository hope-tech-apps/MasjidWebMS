<?php

namespace App\Support;

use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use Illuminate\Support\Facades\Log;

/**
 * Records a SuperAdmin switch flip on an organisation: one append-only
 * `masjid_capability_changes` row and one warning-level log line.
 *
 * Called from inside the DB::transaction that saves the switch, so a save that
 * rolls back leaves no row and a row never exists for a save that did not
 * happen. Called for no-op flips too.
 *
 * WARNING level, not info: production runs LOG_LEVEL=warning, and a line below
 * it is not written at all (.claude/rules/shipping.md).
 */
final class CapabilityLedger
{
    /** The public directory switch, which is not a catalogue key. */
    public const DIRECTORY_LISTING = 'directory_listing';

    public static function record(
        Masjid $masjid,
        string $capability,
        bool $enabledBefore,
        bool $enabledAfter,
        ?bool $overrideBefore,
        ?int $actorUserId,
    ): MasjidCapabilityChange {
        $row = MasjidCapabilityChange::create([
            'masjid_id' => (int) $masjid->id,
            'capability' => $capability,
            'enabled_before' => $enabledBefore,
            'enabled_after' => $enabledAfter,
            'override_before' => $overrideBefore,
            'actor_user_id' => $actorUserId,
        ]);

        Log::warning('Organisation capability changed', [
            'masjid_id' => (int) $masjid->id,
            'capability' => $capability,
            'enabled_before' => $enabledBefore,
            'enabled_after' => $enabledAfter,
            'override_before' => $overrideBefore,
            'actor_user_id' => $actorUserId,
        ]);

        return $row;
    }
}
