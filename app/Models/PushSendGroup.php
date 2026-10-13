<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** One account's provider verdict. Covered by PushSendTenantIsolationTest. */
class PushSendGroup extends Model
{
    use BelongsToMasjid;

    protected $hidden = ['subscription_ids'];

    protected $guarded = ['id'];

    protected $casts = ['legacy' => 'boolean', 'targeted' => 'integer', 'delivered' => 'integer',
        'errored' => 'integer', 'checks' => 'integer', 'subscription_ids' => 'array', 'attempted_at' => 'datetime', 'platform_counts' => 'array'];

    public function send()
    {
        return $this->belongsTo(PushSend::class, 'push_send_id');
    }
}
