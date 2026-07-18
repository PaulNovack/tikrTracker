<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DailyPrice>
 */
class DailyPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $open = fake()->randomFloat(8, 10, 5000);
        $high = $open * fake()->randomFloat(8, 1.001, 1.1);
        $low = $open * fake()->randomFloat(8, 0.9, 0.999);
        $close = fake()->randomFloat(8, $low, $high);

        // Generate unique test symbols and dates to avoid conflicts
        $microtime = substr(str_replace('.', '', microtime(true)), -10);
        $assetType = fake()->randomElement(['stock', 'crypto']);
        $symbol = $assetType === 'stock' ? 'TS_'.$microtime : 'TC_'.$microtime;

        return [
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'date' => fake()->dateTimeBetween('-30 days', '-1 days'), // Use past dates to avoid current conflicts
            'price' => $close,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'volume' => fake()->numberBetween(1000000, 100000000),
        ];
    }
}
