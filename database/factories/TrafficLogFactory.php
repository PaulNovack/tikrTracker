<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TrafficLog>
 */
class TrafficLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $routes = [
            '/dashboard',
            '/market-data/assets',
            '/market-data/technical-analysis',
            '/deposits',
            '/stock-transactions',
        ];

        $durationMs = $this->faker->numberBetween(10, 1000);
        $requestStart = $this->faker->dateTimeBetween('-1 week', 'now');

        return [
            'user_id' => $this->faker->boolean(70) ? User::factory() : null,
            'ip_address' => $this->faker->ipv4(),
            'method' => $method = $this->faker->randomElement($methods),
            'url' => $url = 'http://localhost:8000'.$this->faker->randomElement($routes),
            'route_name' => $this->faker->randomElement(['dashboard', 'asset-info.index', 'technical-analysis.index']),
            'controller_action' => $this->faker->randomElement([
                'App\Http\Controllers\DashboardController@index',
                'App\Http\Controllers\AssetInfoController@index',
            ]),
            'status_code' => $this->faker->randomElement([200, 201, 302, 404, 500]),
            'duration_ms' => $durationMs,
            'query_params' => $this->faker->boolean(30) ? ['page' => $this->faker->numberBetween(1, 10)] : null,
            'post_data' => in_array($method, ['POST', 'PUT', 'PATCH']) && $this->faker->boolean(50)
                ? ['name' => $this->faker->name(), 'email' => $this->faker->email()]
                : null,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => $this->faker->userAgent(),
            ],
            'user_agent' => $this->faker->userAgent(),
            'referer' => $this->faker->boolean(50) ? $this->faker->url() : null,
            'request_start' => $requestStart,
            'request_end' => (clone $requestStart)->modify("+{$durationMs} milliseconds"),
        ];
    }
}
