<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AssetInfo>
 */
class AssetInfoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $assetType = fake()->randomElement(['stock', 'crypto']);

        // Generate truly unique test symbols using microtime (last 10 digits to fit in 20 char limit)
        $microtime = substr(str_replace('.', '', microtime(true)), -10);
        $symbol = $assetType === 'stock'
            ? 'TS_'.$microtime
            : 'TC_'.$microtime;

        $sectors = [
            'Information Technology',
            'Health Care',
            'Financials',
            'Consumer Discretionary',
            'Communication Services',
            'Industrials',
            'Consumer Staples',
            'Energy',
            'Materials',
            'Real Estate',
            'Utilities',
        ];

        return [
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'common_name' => $assetType === 'stock'
                ? fake()->company()
                : fake()->words(2, true).' Coin',
            'description' => fake()->paragraphs(2, true),
            'sector' => $assetType === 'stock' ? fake()->randomElement($sectors) : null,
            'reason_for_delete' => null,
        ];
    }

    public function stock(): static
    {
        return $this->state(fn (array $attributes) => [
            'asset_type' => 'stock',
            'symbol' => fake()->unique()->randomElement(['AAPL', 'MSFT', 'GOOGL', 'AMZN', 'NVDA', 'TSLA']),
            'common_name' => fake()->company(),
        ]);
    }

    public function crypto(): static
    {
        return $this->state(fn (array $attributes) => [
            'asset_type' => 'crypto',
            'symbol' => fake()->unique()->randomElement(['BTC', 'ETH', 'BNB', 'XRP', 'ADA', 'SOL']),
            'common_name' => fake()->words(2, true).' Coin',
        ]);
    }
}
