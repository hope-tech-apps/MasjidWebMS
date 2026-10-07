<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform guide feedback, intentionally without tenant/actor identity or a row id. CLI reads only. */
class GuideUnansweredQuestion extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;
    protected $fillable = ['question', 'created_at', 'books', 'release_version'];
}
