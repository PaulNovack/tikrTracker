<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CpuTemperatureReading extends Model
{
    protected $fillable = [
        'refreshed_at',
        'sensor_section',
        'sensor_label',
        'temperature_celsius',
        'raw_reading',
    ];

    protected function casts(): array
    {
        return [
            'refreshed_at' => 'datetime',
            'temperature_celsius' => 'decimal:1',
        ];
    }
}
