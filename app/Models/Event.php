<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;
    
    protected $fillable = ['masjid_id', 'title', 'details', 'place', 'start', 'end', 'link'];

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }
}
