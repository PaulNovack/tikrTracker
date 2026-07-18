<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'stock_buy_id',
        'type',
        'order_status',
        'time_in_force',
        'exit_reason',
        'symbol',
        'company_name',
        'quantity',
        'price_per_share',
        'avg_price',
        'current_price_per_share',
        'sell_price_per_share',
        'highest_price_reached',
        'fee',
        'total_amount',
        'realized_profit_loss',
        'transaction_date',
        'placed_time',
        'filled_time',
        'notes',
        'broker_order_id',
        'stop_loss',
        'break_even',
        'trailing',
    ];

    protected $appends = ['profit_loss', 'profit_loss_percent'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8',
            'price_per_share' => 'decimal:2',
            'avg_price' => 'decimal:2',
            'current_price_per_share' => 'decimal:2',
            'sell_price_per_share' => 'decimal:2',
            'highest_price_reached' => 'decimal:2',
            'fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'realized_profit_loss' => 'decimal:2',
            'stop_loss' => 'decimal:2',
            'break_even' => 'decimal:2',
            'trailing' => 'decimal:2',
            'transaction_date' => 'datetime',
            'placed_time' => 'datetime',
            'filled_time' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stockBuy(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_buy_id');
    }

    public function buyTransaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_buy_id');
    }

    public function sales()
    {
        return $this->hasMany(StockTransaction::class, 'stock_buy_id');
    }

    // Accessor for profit_loss_percent
    public function getProfitLossPercentAttribute(): ?float
    {
        if ($this->type === 'sell' && $this->buyTransaction) {
            $buyPrice = (float) $this->buyTransaction->price_per_share;
            $sellPrice = (float) $this->price_per_share;

            return round((($sellPrice - $buyPrice) / $buyPrice) * 100, 2);
        }

        return null;
    }

    public function isBuy(): bool
    {
        return $this->type === 'buy';
    }

    public function isSell(): bool
    {
        return $this->type === 'sell';
    }

    public function isSold(): bool
    {
        return $this->isBuy() && $this->remaining_quantity == 0;
    }

    public function getRemainingQuantityAttribute(): float
    {
        if ($this->isSell()) {
            return 0.0;
        }

        $soldQuantity = $this->sales()->sum('quantity');

        return (float) ($this->quantity - $soldQuantity);
    }

    public function getProfitLossAttribute(): ?float
    {
        // For sell transactions, calculate P/L using linked buy transaction
        if ($this->type === 'sell' && $this->buyTransaction) {
            $buyPrice = (float) $this->buyTransaction->price_per_share;
            $sellPrice = (float) $this->price_per_share;
            $quantity = (float) $this->quantity;

            return round(($sellPrice - $buyPrice) * $quantity, 2);
        }

        // For sell transactions, use stored realized_profit_loss if available
        if ($this->isSell() && $this->realized_profit_loss !== null) {
            return (float) $this->realized_profit_loss;
        }

        // For buy transactions with current price, calculate unrealized P/L
        if ($this->isBuy() && $this->current_price_per_share) {
            $remainingQty = $this->remaining_quantity;
            if ($remainingQty <= 0) {
                return null;
            }

            // Unrealized P/L = (Remaining Qty * Current Price) - (Remaining Qty * Purchase Price)
            $currentValue = $remainingQty * $this->current_price_per_share;
            $costBasis = $remainingQty * $this->price_per_share;

            return (float) ($currentValue - $costBasis);
        }

        return null;
    }

    /**
     * Calculate and set realized profit/loss for a sell transaction
     */
    public function calculateRealizedProfitLoss(): ?float
    {
        if ($this->isBuy() || ! $this->stockBuy) {
            return null;
        }

        // Get the sell price (use sell_price_per_share if set, otherwise current_price_per_share)
        $sellPrice = $this->sell_price_per_share ?? $this->current_price_per_share;

        if (! $sellPrice) {
            return null;
        }

        // Revenue from sell
        $sellRevenue = $this->quantity * $sellPrice;

        // Cost basis includes original purchase price and proportional buy fee
        $buyTransaction = $this->stockBuy;
        $purchaseCost = $this->quantity * $buyTransaction->price_per_share;

        // Proportional buy fee (if selling partial position)
        $proportionalBuyFee = ($this->quantity / $buyTransaction->quantity) * $buyTransaction->fee;

        // Total cost basis
        $totalCostBasis = $purchaseCost + $proportionalBuyFee;

        // Realized P/L = Revenue - Cost Basis - Sell Fee
        $realizedPL = $sellRevenue - $totalCostBasis - $this->fee;

        return (float) $realizedPL;
    }
}
