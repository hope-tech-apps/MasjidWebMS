<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class StripeConnectOAuthState extends Model
{
    use BelongsToMasjid;

    protected $table = 'stripe_connect_oauth_states';

    protected $fillable = ['masjid_id', 'actor_user_id', 'nonce_hash', 'expires_at'];

    protected $hidden = ['nonce_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
