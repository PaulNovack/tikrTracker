<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SqlLog>
 */
class SqlLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query' => 'SELECT * FROM '.$this->faker->word().' WHERE id = ?',
            'bindings' => null,
            'execution_time_ms' => $this->faker->randomFloat(2, 0, 100),
            'connection' => 'mysql',
            'request_path' => $this->faker->slug(),
            'http_method' => $this->faker->randomElement(['GET', 'POST', 'PUT', 'DELETE', 'PATCH']),
            'user_id' => null,
            'stack_trace' => null,
        ];
    }
}
