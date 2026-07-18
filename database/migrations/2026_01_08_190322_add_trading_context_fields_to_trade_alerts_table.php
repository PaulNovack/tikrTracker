<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->time('time_of_day')->nullable()->after('signal_ts_est')->comment('Hour:minute of signal time for filtering morning vs afternoon patterns');
            $table->integer('consolidation_bars')->nullable()->after('five_min_net_progress')->comment('Number of bars in consolidation before breakout');
            $table->decimal('breakout_volume_ratio', 10, 3)->nullable()->after('consolidation_bars')->comment('Volume ratio on breakout bar vs average');
            $table->decimal('max_adverse_excursion', 10, 4)->nullable()->after('pnl_dollar')->comment('Largest unrealized loss during trade (for stop optimization)');
            $table->integer('hold_time_minutes')->nullable()->after('max_adverse_excursion')->comment('Duration of trade in minutes');

            $table->index('time_of_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropIndex(['time_of_day']);
            $table->dropColumn([
                'time_of_day',
                'consolidation_bars',
                'breakout_volume_ratio',
                'max_adverse_excursion',
                'hold_time_minutes',
            ]);
        });
    }
};
