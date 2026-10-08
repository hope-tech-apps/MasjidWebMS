<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Global spending ledger, including the platform ceiling; no HTTP read surface. */
class GuideAskCounter extends Model
{
    public $timestamps = false;
    protected $fillable = ['scope_key', 'period', 'count'];
}
