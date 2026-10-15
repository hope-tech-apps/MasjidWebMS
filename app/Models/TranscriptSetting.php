<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant isolation: TranscriptsTest. */
class TranscriptSetting extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id', 'points', 'honors_extra', 'ap_extra', 'failing_letters'];
    protected function casts(): array { return ['points'=>'array','failing_letters'=>'array','honors_extra'=>'integer','ap_extra'=>'integer']; }
}
