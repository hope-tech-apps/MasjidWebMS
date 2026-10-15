<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class StripeConnectHistory extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'actor_user_id', 'account_last4', 'action'];

    public function actor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
