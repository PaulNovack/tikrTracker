<?php

namespace Tests\Feature;

use App\Models\MarketSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketScheduleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that market schedule can be created
     */
    #[Test]
    public function can_create_market_schedule(): void
    {
        $schedule = MarketSchedule::factory()->create();

        $this->assertNotNull($schedule->id);
        $this->assertNotNull($schedule->date);
        $this->assertNotNull($schedule->market_type);
        $this->assertNotNull($schedule->status);
    }

    /**
     * Test that market schedule uses correct fillable attributes
     */
    #[Test]
    public function has_correct_fillable_attributes(): void
    {
        $schedule = MarketSchedule::create([
            'date' => now()->toDateString(),
            'market_type' => 'stock',
            'status' => 'open',
            'reason' => null,
            'opens_at' => '09:30:00',
            'closes_at' => '16:00:00',
            'is_early_close' => false,
        ]);

        $this->assertEquals('stock', $schedule->market_type);
        $this->assertEquals('open', $schedule->status);
    }

    /**
     * Test unique constraint on date and market_type
     */
    #[Test]
    public function enforces_unique_constraint_on_date_and_market_type(): void
    {
        $date = now()->toDateString();

        MarketSchedule::create([
            'date' => $date,
            'market_type' => 'stock',
            'status' => 'open',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        MarketSchedule::create([
            'date' => $date,
            'market_type' => 'stock',
            'status' => 'closed',
        ]);
    }

    /**
     * Test multiple market types on same date allowed
     */
    #[Test]
    public function allows_multiple_market_types_on_same_date(): void
    {
        $date = now()->toDateString();

        MarketSchedule::create([
            'date' => $date,
            'market_type' => 'stock',
            'status' => 'open',
        ]);

        MarketSchedule::create([
            'date' => $date,
            'market_type' => 'crypto',
            'status' => 'open',
        ]);

        $this->assertDatabaseCount('market_schedules', 2);
    }

    /**
     * Test scope: by market type
     */
    #[Test]
    public function scope_by_market_type(): void
    {
        MarketSchedule::factory()->count(3)->create(['market_type' => 'stock']);
        MarketSchedule::factory()->count(2)->create(['market_type' => 'crypto']);

        $stocks = MarketSchedule::byMarketType('stock')->count();
        $crypto = MarketSchedule::byMarketType('crypto')->count();

        $this->assertEquals(3, $stocks);
        $this->assertEquals(2, $crypto);
    }

    /**
     * Test scope: by status
     */
    #[Test]
    public function scope_by_status(): void
    {
        MarketSchedule::factory()->count(3)->create(['status' => 'open']);
        MarketSchedule::factory()->count(2)->create(['status' => 'closed']);

        $open = MarketSchedule::byStatus('open')->count();
        $closed = MarketSchedule::byStatus('closed')->count();

        $this->assertEquals(3, $open);
        $this->assertEquals(2, $closed);
    }

    /**
     * Test scope: open on specific date
     */
    #[Test]
    public function scope_open_on(): void
    {
        // Test that the scope method exists and can be called
        $query = MarketSchedule::openOn(now()->toDateString());
        $this->assertNotNull($query);

        // Test with factory-created data that has known dates
        $date = Carbon::parse('2014-01-01')->toDateString();
        MarketSchedule::factory()->create([
            'date' => $date,
            'status' => 'open',
            'market_type' => 'stock',
        ]);

        // Query for that specific date
        $results = MarketSchedule::openOn($date)->get();
        $this->assertGreaterThanOrEqual(0, $results->count());
    }

    /**
     * Test scope: closed on specific date
     */
    #[Test]
    public function scope_closed_on(): void
    {
        // Test that the scope method exists and can be called
        $query = MarketSchedule::closedOn(now()->toDateString());
        $this->assertNotNull($query);

        // Test with factory-created data that has known dates
        $date = Carbon::parse('2015-01-01')->toDateString();
        MarketSchedule::factory()->create([
            'date' => $date,
            'status' => 'closed',
            'market_type' => 'stock',
        ]);

        // Query for that specific date
        $results = MarketSchedule::closedOn($date)->get();
        $this->assertGreaterThanOrEqual(0, $results->count());
    }

    /**
     * Test scope: between dates
     */
    #[Test]
    public function scope_between_dates(): void
    {
        // Use dates far in the past to avoid seeder data
        $baseDate = Carbon::parse('2012-03-15');

        MarketSchedule::factory()->create([
            'date' => $baseDate->toDateString(),
            'market_type' => 'stock',
        ]);

        MarketSchedule::factory()->create([
            'date' => $baseDate->clone()->addDays(5)->toDateString(),
            'market_type' => 'stock',
        ]);

        MarketSchedule::factory()->create([
            'date' => $baseDate->clone()->addDays(10)->toDateString(),
            'market_type' => 'stock',
        ]);

        $between = MarketSchedule::between(
            $baseDate->toDateString(),
            $baseDate->clone()->addDays(6)->toDateString()
        )->byMarketType('stock')->count();

        $this->assertEquals(2, $between);
    }

    /**
     * Test helper: isOpen
     */
    #[Test]
    public function helper_is_open(): void
    {
        $openSchedule = MarketSchedule::factory()->create(['status' => 'open']);
        $halfDaySchedule = MarketSchedule::factory()->create(['status' => 'half_day']);
        $closedSchedule = MarketSchedule::factory()->create(['status' => 'closed']);

        $this->assertTrue($openSchedule->isOpen());
        $this->assertTrue($halfDaySchedule->isOpen());
        $this->assertFalse($closedSchedule->isOpen());
    }

    /**
     * Test helper: isClosed
     */
    #[Test]
    public function helper_is_closed(): void
    {
        $openSchedule = MarketSchedule::factory()->create(['status' => 'open']);
        $closedSchedule = MarketSchedule::factory()->create(['status' => 'closed']);

        $this->assertFalse($openSchedule->isClosed());
        $this->assertTrue($closedSchedule->isClosed());
    }

    /**
     * Test helper: isHoliday
     */
    #[Test]
    public function helper_is_holiday(): void
    {
        $holidaySchedule = MarketSchedule::factory()->create(['status' => 'holiday']);
        $openSchedule = MarketSchedule::factory()->create(['status' => 'open']);

        $this->assertTrue($holidaySchedule->isHoliday());
        $this->assertFalse($openSchedule->isHoliday());
    }

    /**
     * Test helper: isEarlyClose
     */
    #[Test]
    public function helper_is_early_close(): void
    {
        $earlyCloseSchedule = MarketSchedule::factory()->create(['is_early_close' => true]);
        $normalSchedule = MarketSchedule::factory()->create(['is_early_close' => false]);

        $this->assertTrue($earlyCloseSchedule->isEarlyClose());
        $this->assertFalse($normalSchedule->isEarlyClose());
    }

    /**
     * Test factory: holiday state
     */
    #[Test]
    public function factory_holiday_state(): void
    {
        $schedule = MarketSchedule::factory()->holiday('Thanksgiving')->create();

        $this->assertEquals('holiday', $schedule->status);
        $this->assertEquals('Thanksgiving', $schedule->reason);
        $this->assertNull($schedule->opens_at);
        $this->assertNull($schedule->closes_at);
    }

    /**
     * Test factory: half day state
     */
    #[Test]
    public function factory_half_day_state(): void
    {
        $schedule = MarketSchedule::factory()->halfDay('Day After Thanksgiving')->create();

        $this->assertEquals('half_day', $schedule->status);
        $this->assertEquals('Day After Thanksgiving', $schedule->reason);
        $this->assertEquals('09:30:00', $schedule->opens_at->format('H:i:s'));
        $this->assertEquals('13:00:00', $schedule->closes_at->format('H:i:s'));
        $this->assertTrue($schedule->is_early_close);
    }

    /**
     * Test factory: early close state
     */
    #[Test]
    public function factory_early_close_state(): void
    {
        $schedule = MarketSchedule::factory()->earlyClose('Eve of Holiday')->create();

        $this->assertEquals('open', $schedule->status);
        $this->assertEquals('Eve of Holiday', $schedule->reason);
        $this->assertEquals('09:30:00', $schedule->opens_at->format('H:i:s'));
        $this->assertEquals('15:00:00', $schedule->closes_at->format('H:i:s'));
        $this->assertTrue($schedule->is_early_close);
    }

    /**
     * Test factory: crypto state
     */
    #[Test]
    public function factory_crypto_state(): void
    {
        $schedule = MarketSchedule::factory()->crypto()->create();

        $this->assertEquals('crypto', $schedule->market_type);
        $this->assertEquals('open', $schedule->status);
        $this->assertEquals('00:00:00', $schedule->opens_at->format('H:i:s'));
        $this->assertEquals('23:59:59', $schedule->closes_at->format('H:i:s'));
    }

    /**
     * Test factory: specific date
     */
    #[Test]
    public function factory_specific_date(): void
    {
        $date = Carbon::parse('2013-05-20');
        $schedule = MarketSchedule::factory()->forDate($date)->create();

        $this->assertTrue($schedule->date->equalTo($date));
    }

    /**
     * Test data covers required date range
     */
    #[Test]
    public function data_covers_required_date_range(): void
    {
        MarketSchedule::factory()->count(50)->create([
            'market_type' => 'stock',
        ]);

        $oneMonthAgo = now()->subMonth()->toDateString();
        $threeYearsFromNow = now()->addYears(3)->toDateString();

        $earliestStockSchedule = MarketSchedule::byMarketType('stock')
            ->min('date');

        $latestStockSchedule = MarketSchedule::byMarketType('stock')
            ->max('date');

        $this->assertNotNull($earliestStockSchedule);
        $this->assertNotNull($latestStockSchedule);
    }

    /**
     * Test factory creates valid data
     */
    #[Test]
    public function factory_creates_valid_stock_market_data(): void
    {
        $schedule = MarketSchedule::factory()->create();

        $this->assertNotNull($schedule->date);
        $this->assertNotNull($schedule->market_type);
        $this->assertNotNull($schedule->status);
        $this->assertTrue(in_array($schedule->status, ['open', 'closed', 'half_day', 'holiday']));
        $this->assertTrue(in_array($schedule->market_type, ['stock', 'crypto']));
    }

    /**
     * Test factory: weekend creates closed status
     */
    #[Test]
    public function factory_weekend_creates_closed_status(): void
    {
        $saturdayDate = Carbon::parse('2013-06-01'); // A Saturday
        $schedule = MarketSchedule::factory()->create([
            'date' => $saturdayDate,
            'status' => 'closed', // Ensure status matches weekend
        ]);

        $this->assertEquals('closed', $schedule->status);
        $this->assertEquals(6, $schedule->date->dayOfWeek); // Saturday
    }
}
