<?php

namespace Database\Factories;

use App\Models\PriceAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AlertLog>
 */
class AlertLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'price_alert_id' => PriceAlert::factory(),
            'symbol' => fake()->randomElement(['GOOGL', 'AAPL', 'MSFT', 'AMZN', 'TSLA']),
            'direction' => fake()->randomElement(['up', 'down']),
            'trigger_price' => fake()->randomFloat(2, 100, 500),
            'current_price' => fake()->randomFloat(2, 100, 500),
            'trigger_percentage' => fake()->randomFloat(2, 1, 5),
            'email_status' => 'sent',
            'email_error' => null,
            'sent_at' => now()->subMinutes(fake()->numberBetween(5, 60)),
        ];
    }
}
