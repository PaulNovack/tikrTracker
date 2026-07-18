<?php

namespace Database\Factories;

use App\Models\AssetInfo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PriceAlert>
 */
class PriceAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $basePrice = $this->faker->randomFloat(2, 50, 500);

        return [
            'user_id' => User::factory(),
            'asset_info_id' => AssetInfo::factory(),
            'base_price' => $basePrice,
            'alert_type' => 'percentage',
            'threshold_value' => 2.5,
            'up_percentage' => 2.5,
            'down_percentage' => 2.5,
            'above_price' => $basePrice * 1.025,
            'below_price' => $basePrice * 0.975,
            'enabled' => true,
            'up_enabled' => true,
            'down_enabled' => true,
            'above_triggered' => false,
            'below_triggered' => false,
            'last_triggered_at' => null,
        ];
    }

    /**
     * Indicate that the alert is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
            'up_enabled' => false,
            'down_enabled' => false,
        ]);
    }

    /**
     * Indicate that the alert has been triggered.
     */
    public function triggered(): static
    {
        return $this->state(fn (array $attributes) => [
            'above_triggered' => true,
            'below_triggered' => true,
            'last_triggered_at' => now(),
        ]);
    }

    /**
     * Indicate that only the up alert is enabled.
     */
    public function upOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'up_enabled' => true,
            'down_enabled' => false,
        ]);
    }

    /**
     * Indicate that only the down alert is enabled.
     */
    public function downOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'up_enabled' => false,
            'down_enabled' => true,
        ]);
    }
}
