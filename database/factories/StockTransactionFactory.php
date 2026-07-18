<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockTransaction>
 */
class StockTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['buy', 'sell']);
        $quantity = fake()->randomFloat(8, 1, 100);
        $pricePerShare = fake()->randomFloat(2, 10, 500);
        $fee = fake()->randomFloat(2, 0, 10);

        // For buy transactions, set current_price_per_share
        // For sell transactions, set sell_price_per_share
        if ($type === 'buy') {
            $currentPricePerShare = fake()->randomFloat(2, $pricePerShare * 0.8, $pricePerShare * 1.3);
            $totalAmount = ($quantity * $pricePerShare) + $fee;
            $sellPricePerShare = null;
        } else {
            $currentPricePerShare = null;
            $sellPricePerShare = fake()->randomFloat(2, $pricePerShare * 0.7, $pricePerShare * 1.5);
            $totalAmount = ($quantity * $sellPricePerShare) - $fee;
        }

        return [
            'user_id' => \App\Models\User::factory(),
            'stock_buy_id' => null,
            'type' => $type,
            'exit_reason' => null,
            'symbol' => fake()->randomElement(['AAPL', 'GOOGL', 'MSFT', 'AMZN', 'TSLA', 'META', 'NVDA', 'AMD', 'NFLX', 'SPY']),
            'quantity' => $quantity,
            'price_per_share' => $pricePerShare,
            'current_price_per_share' => $currentPricePerShare,
            'sell_price_per_share' => $sellPricePerShare,
            'highest_price_reached' => null,
            'fee' => $fee,
            'total_amount' => $totalAmount,
            'realized_profit_loss' => null,
            'transaction_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'notes' => fake()->optional(0.3)->sentence(),
            'stop_loss' => null,
            'break_even' => null,
            'trailing' => null,
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this;
    }

    public function buy(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'buy',
        ]);
    }

    public function sell(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'sell',
        ]);
    }

    public function linkedSell(\App\Models\StockTransaction $buyTransaction): static
    {
        $sellPrice = fake()->randomFloat(2, $buyTransaction->price_per_share * 0.7, $buyTransaction->price_per_share * 1.5);
        $sellFee = fake()->randomFloat(2, 0, 10);
        $sellTotal = ($buyTransaction->quantity * $sellPrice) - $sellFee;

        // Calculate realized P/L
        $sellRevenue = $buyTransaction->quantity * $sellPrice;
        $purchaseCost = $buyTransaction->quantity * $buyTransaction->price_per_share;
        $totalCostBasis = $purchaseCost + $buyTransaction->fee;
        $realizedPL = $sellRevenue - $totalCostBasis - $sellFee;

        // Valid exit reasons from enum
        $exitReason = fake()->randomElement(['manual', 'stop_loss', 'break_even', 'trailing_stop', 'take_profit']);

        return $this->state(fn (array $attributes) => [
            'type' => 'sell',
            'user_id' => $buyTransaction->user_id,
            'stock_buy_id' => $buyTransaction->id,
            'exit_reason' => $exitReason,
            'symbol' => $buyTransaction->symbol,
            'quantity' => $buyTransaction->quantity,
            'price_per_share' => $buyTransaction->price_per_share,
            'current_price_per_share' => null, // Not used for sells anymore
            'sell_price_per_share' => $sellPrice,
            'highest_price_reached' => fake()->randomFloat(2, $buyTransaction->price_per_share, $sellPrice * 1.1),
            'fee' => $sellFee,
            'total_amount' => $sellTotal,
            'realized_profit_loss' => $realizedPL,
            'transaction_date' => fake()->dateTimeBetween($buyTransaction->transaction_date, 'now'),
        ]);
    }
}
