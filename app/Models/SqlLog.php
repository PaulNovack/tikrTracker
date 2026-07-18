<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SqlLog extends Model
{
    /** @use HasFactory<\Database\Factories\SqlLogFactory> */
    use HasFactory;

    protected $fillable = [
        'query',
        'bindings',
        'execution_time_ms',
        'connection',
        'request_path',
        'http_method',
        'user_id',
        'stack_trace',
        'cached_data',
    ];

    protected function casts(): array
    {
        return [
            'bindings' => 'string', // Store as JSON string to avoid memory issues
            'execution_time_ms' => 'float',
            'cached_data' => 'boolean',
        ];
    }
}
