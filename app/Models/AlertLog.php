<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertLog extends Model
{
    /** @use HasFactory<\Database\Factories\AlertLogFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'price_alert_id',
        'symbol',
        'direction',
        'trigger_price',
        'current_price',
        'trigger_percentage',
        'email_status',
        'email_error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_price' => 'decimal:2',
            'current_price' => 'decimal:2',
            'trigger_percentage' => 'decimal:2',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function priceAlert(): BelongsTo
    {
        return $this->belongsTo(PriceAlert::class);
    }
}
