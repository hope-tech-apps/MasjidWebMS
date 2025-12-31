<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IqamaTimeRange extends Model
{
    protected $fillable = [
        'iqama_time_setting_id',
        'salah',
        'start_date',
        'end_date',
        'specific_time'
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function iqamaTimeSetting()
    {
        return $this->belongsTo(IqamaTimeSetting::class);
    }
}

