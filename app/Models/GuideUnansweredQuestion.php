<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform guide feedback, intentionally without tenant/actor identity in its four content fields. The primary key identifies a row only. CLI reads only. */
class GuideUnansweredQuestion extends Model
{
    public $timestamps = false;
    protected $fillable = ['question', 'created_at', 'books', 'release_version'];
}
