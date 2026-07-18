<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = ['info', 'success', 'warning', 'error'];
        $isRead = $this->faker->boolean(30); // 30% chance of being read

        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'type' => $this->faker->randomElement($types),
            'read' => $isRead,
            'read_at' => $isRead ? $this->faker->dateTimeBetween('-30 days', 'now') : null,
            'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ];
    }

    public function unread(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'read' => false,
                'read_at' => null,
            ];
        });
    }

    public function read(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'read' => true,
                'read_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            ];
        });
    }
}
