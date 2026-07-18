<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficLog extends Model
{
    /** @use HasFactory<\Database\Factories\TrafficLogFactory> */
    use HasFactory;

    protected $table = 'traffic_logs';

    protected $fillable = [
        'user_id',
        'ip_address',
        'method',
        'url',
        'full_url',
        'route_name',
        'controller_action',
        'status_code',
        'duration_ms',
        'query_params',
        'post_data',
        'headers',
        'user_agent',
        'referer',
        'request_start',
        'request_end',
    ];

    protected function casts(): array
    {
        return [
            'query_params' => 'array',
            'post_data' => 'array',
            'headers' => 'array',
            'request_start' => 'datetime',
            'request_end' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
