<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform registry, independent of the organisation a device currently selects. */
class PushApp extends Model
{
    protected $fillable = ['provider_app_id', 'rest_api_key', 'label', 'active'];

    protected $hidden = ['rest_api_key'];

    protected $casts = ['rest_api_key' => 'encrypted', 'active' => 'boolean'];
}
