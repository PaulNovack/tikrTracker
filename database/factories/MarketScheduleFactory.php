<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MarketSchedule>
 */
class MarketScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-30 days', '+3 years');
        $dayOfWeek = $date->format('N'); // 1=Monday, 7=Sunday

        // US Stock Market is closed on weekends
        $isWeekend = $dayOfWeek >= 6;
        $status = $isWeekend ? 'closed' : 'open';

        return [
            'date' => $date,
            'market_type' => 'stock',
            'status' => $status,
            'reason' => $isWeekend ? 'Weekend' : null,
            'opens_at' => $status === 'open' ? '09:30:00' : null,
            'closes_at' => $status === 'open' ? '16:00:00' : null,
            'is_early_close' => false,
        ];
    }

    /**
     * State for holiday closure
     */
    public function holiday(string $reason = 'Holiday'): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'holiday',
            'reason' => $reason,
            'opens_at' => null,
            'closes_at' => null,
            'is_early_close' => false,
        ]);
    }

    /**
     * State for half day
     */
    public function halfDay(string $reason = 'Half Day'): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'half_day',
            'reason' => $reason,
            'opens_at' => '09:30:00',
            'closes_at' => '13:00:00',
            'is_early_close' => true,
        ]);
    }

    /**
     * State for early close
     */
    public function earlyClose(string $reason = 'Early Close'): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'reason' => $reason,
            'opens_at' => '09:30:00',
            'closes_at' => '15:00:00',
            'is_early_close' => true,
        ]);
    }

    /**
     * State for crypto market (open 24/7)
     */
    public function crypto(): self
    {
        return $this->state(fn (array $attributes) => [
            'market_type' => 'crypto',
            'status' => 'open',
            'reason' => null,
            'opens_at' => '00:00:00',
            'closes_at' => '23:59:59',
            'is_early_close' => false,
        ]);
    }

    /**
     * State for specific date
     */
    public function forDate($date): self
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }
}
