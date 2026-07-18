<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'stop_loss_percent',
        'break_even',
        'trailing',
    ];

    protected function casts(): array
    {
        return [
            'stop_loss_percent' => 'decimal:2',
            'break_even' => 'decimal:2',
            'trailing' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
