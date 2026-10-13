<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Stored send totals; delivery is never inferred from a provider message id. Covered by PushSendTenantIsolationTest. */
class PushSend extends Model
{
    use BelongsToMasjid;

    protected $guarded = ['id'];

    protected $casts = ['targeted' => 'integer', 'delivered' => 'integer', 'errored' => 'integer',
        'unknown_app' => 'integer', 'platform_counts' => 'array', 'readback_scheduled_at' => 'datetime'];

    public function groups()
    {
        return $this->hasMany(PushSendGroup::class);
    }

    /** Recompute from groups, never increment totals, so retries cannot inflate them. */
    public function aggregate(): void
    {
        $groups = $this->groups()->withoutGlobalScopes()->where('masjid_id', $this->masjid_id)->get();
        $platforms = [];
        foreach ($groups as $group) {
            foreach ($group->platform_counts ?? [] as $platform => $counts) {
                foreach ($counts as $key => $count) {
                    $platforms[$platform][$key] = ($platforms[$platform][$key] ?? 0) + $count;
                }
            }
        }
        $checking = $groups->contains(fn ($g) => in_array($g->status, ['pending', 'sending', 'checking'], true));
        $unknown = $groups->contains(fn ($g) => in_array($g->status, ['unknown', 'send_failed', 'rejected'], true));
        $this->forceFill([
            'targeted' => $groups->sum('targeted'),
            'delivered' => $groups->whereNotNull('delivered')->isEmpty() ? null : $groups->sum('delivered'),
            'errored' => $groups->whereNotNull('errored')->isEmpty() ? null : $groups->sum('errored'),
            'unknown_app' => $groups->where('status', 'unknown_app')->sum('targeted'),
            'platform_counts' => $platforms ?: null,
            'status' => $checking ? 'checking' : ($unknown ? 'unknown' : 'complete'),
        ])->save();
    }

    /** Office wording uses only stored counts, with uncounted targets left unclaimed. */
    public function wording(): string
    {
        $groups = $this->groups()->withoutGlobalScopes()->where('masjid_id', $this->masjid_id)->get();
        $accepted = $groups->whereNotNull('provider_message_id');
        $sent = $accepted->sum('targeted');
        $rejected = $groups->whereNull('provider_message_id')->where('status', 'rejected')->sum('targeted');
        $unreachable = $groups->where('status', 'unknown_app')->sum('targeted');
        $unknown = $groups->whereNull('provider_message_id')
            ->whereIn('status', ['sending', 'unknown', 'send_failed'])->sum('targeted');
        $parts = [];
        $noun = fn ($count) => $count === 1 ? 'phone' : 'phones';
        if ($sent > 0) {
            $text = "Sent to {$sent} ".$noun($sent).'.';
            if ($accepted->contains(fn ($g) => $g->status === 'checking')) {
                $text .= ' Checking delivery…';
            } else {
                $delivered = $accepted->sum('delivered');
                $errored = $accepted->sum('errored');
                if ($accepted->whereNotNull('delivered')->isNotEmpty()) {
                    $text .= " Delivered to {$delivered}.";
                }
                if ($errored > 0) {
                    $names = ['ios' => 'iPhone', 'android' => 'Android'];
                    $platformErrors = 0; $failedPlatforms = [];
                    foreach ($this->platform_counts ?? [] as $platform => $counts) {
                        if (($counts['errored'] ?? 0) > 0) {
                            $platformErrors += $counts['errored'];
                            $failedPlatforms[] = $names[$platform] ?? 'other platform';
                        }
                    }
                    $suffix = $platformErrors === $errored && $failedPlatforms
                        ? ' ('.implode(', ', array_unique($failedPlatforms)).')' : '';
                    $text .= " {$errored} could not be delivered{$suffix}.";
                }
                // Count the unconfirmed remainder per account, so one account's counts cannot mask another's.
                foreach ($accepted as $group) {
                    $unknown += max(0, $group->targeted - ($group->delivered ?? 0) - ($group->errored ?? 0));
                }
            }
            $parts[] = $text;
        }
        if ($rejected > 0) {
            $parts[] = "Could not be sent to {$rejected} ".$noun($rejected).' (the push service refused the request).';
        }
        if ($unknown > 0) {
            $parts[] = "Delivery could not be confirmed for {$unknown} ".$noun($unknown).'.';
        }
        if ($unreachable > 0) {
            $parts[] = "{$unreachable} ".$noun($unreachable).' could not be reached: their app is not set up for push.';
        }
        if ($groups->contains(fn ($g) => $g->status === 'pending')) {
            $parts[] = 'Waiting to send.';
        }
        return implode(' ', $parts) ?: 'No phones selected.';
    }
}
