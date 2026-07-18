<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HourlyPrice>
 */
class HourlyPriceFactory extends Factory
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
            'ts' => fake()->dateTimeBetween('-7 days', 'now'),
            'price' => fake()->randomFloat(8, 10, 5000),
            'volume' => fake()->numberBetween(100000, 10000000),
        ];
    }
}
