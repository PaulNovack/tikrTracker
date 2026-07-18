<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FiveMinutePrice>
 */
class FiveMinutePriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'symbol' => fake()->randomElement(['AAPL', 'BTC', 'ETH', 'TSLA', 'MSFT']),
            'asset_type' => fake()->randomElement(['stock', 'crypto']),
            'ts' => fake()->dateTimeBetween('-1 day', 'now'),
            'price' => fake()->randomFloat(8, 10, 5000),
            'volume' => fake()->numberBetween(100000, 10000000),
        ];
    }

    public function stock(): static
    {
        return $this->state(fn (array $attributes) => [
            'asset_type' => 'stock',
            'symbol' => fake()->randomElement(['AAPL', 'TSLA', 'MSFT', 'GOOGL', 'AMZN']),
        ]);
    }

    public function crypto(): static
    {
        return $this->state(fn (array $attributes) => [
            'asset_type' => 'crypto',
            'symbol' => fake()->randomElement(['BTC', 'ETH', 'SOL', 'XRP', 'DOGE']),
        ]);
    }
}
