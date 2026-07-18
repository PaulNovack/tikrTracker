<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Deposit>
 */
class DepositFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'notes' => fake()->optional(0.3)->sentence(),
            'deposited_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
