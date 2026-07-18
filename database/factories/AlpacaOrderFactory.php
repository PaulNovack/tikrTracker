<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AlpacaOrder>
 */
class AlpacaOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alpaca_order_id' => fake()->uuid(),
            'paper' => (bool) config('alpaca.paper_trading', true),
            'is_paper' => (bool) config('alpaca.paper_trading', true),
            'symbol' => fake()->randomElement(['AAPL', 'MSFT', 'GOOGL', 'TSLA', 'NVDA']),
            'side' => fake()->randomElement(['buy', 'sell']),
            'order_type' => 'market',
            'qty' => fake()->numberBetween(1, 100),
            'filled_qty' => 0,
            'status' => 'pending_new',
        ];
    }

    public function stopLoss(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_type' => 'stop',
            'side' => 'sell',
            'stop_price' => fake()->randomFloat(2, 100, 500),
        ]);
    }

    public function filled(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'filled',
                'filled_qty' => $attributes['qty'],
                'filled_avg_price' => fake()->randomFloat(2, 100, 500),
                'filled_at' => now(),
            ];
        });
    }

    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'canceled',
            'canceled_at' => now(),
        ]);
    }
}
