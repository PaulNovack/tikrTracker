<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MlProbabilityCalibration extends Model
{
    protected $table = 'ml_probability_calibration';

    protected $fillable = [
        'pipeline',
        'version',
        'bucket_min',
        'bucket_max',
        'bucket_label',
        'rows',
        'win_rate',
        'avg_pnl',
        'median_pnl',
    ];

    protected function casts(): array
    {
        return [
            'bucket_min' => 'decimal:4',
            'bucket_max' => 'decimal:4',
            'win_rate' => 'decimal:6',
            'avg_pnl' => 'decimal:4',
            'median_pnl' => 'decimal:4',
        ];
    }
}
