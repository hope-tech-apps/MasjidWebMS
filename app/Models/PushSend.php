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
        $checking = $groups->contains(fn ($g) => $g->status === 'checking');
        $unknown = $groups->contains(fn ($g) => in_array($g->status, ['unknown', 'send_failed'], true));
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
        $phones = $this->targeted === 1 ? 'phone' : 'phones';
        $text = $this->unknown_app > 0 ? "Targeted {$this->targeted} {$phones}." : "Sent to {$this->targeted} {$phones}.";
        if ($this->status === 'checking') {
            $text .= ' Checking delivery…';
        } elseif ($this->status === 'unknown') {
            $text .= ' Delivery could not be checked.';
        } elseif ($this->delivered !== null) {
            $text .= " Delivered to {$this->delivered}.";
        }
        if ($this->errored > 0) {
            $platformNames = ['ios' => 'iPhone', 'android' => 'Android'];
            $failedPlatforms = [];
            $platformErrors = 0;
            foreach ($this->platform_counts ?? [] as $platform => $counts) {
                if (($counts['errored'] ?? 0) > 0) {
                    $platformErrors += $counts['errored'];
                    $failedPlatforms[] = $platformNames[$platform] ?? 'other platform';
                }
            }
            $suffix = $platformErrors === $this->errored && $failedPlatforms
                ? ' ('.implode(', ', array_unique($failedPlatforms)).')' : '';
            $text .= " {$this->errored} could not be delivered{$suffix}.";
        }
        if ($this->unknown_app > 0) {
            $text .= " {$this->unknown_app} not deliverable: unknown app.";
        }
        return $text;
    }
}
