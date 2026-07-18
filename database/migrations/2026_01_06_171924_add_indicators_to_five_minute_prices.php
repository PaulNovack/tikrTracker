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
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->decimal('rsi_14', 10, 6)->nullable()->after('atr_pct');
            $table->decimal('bb_upper', 20, 8)->nullable()->after('rsi_14');
            $table->decimal('bb_middle', 20, 8)->nullable()->after('bb_upper');
            $table->decimal('bb_lower', 20, 8)->nullable()->after('bb_middle');

            // Add index for bottom detection queries
            $table->index(['asset_type', 'symbol', 'ts_est'], 'idx_indicator_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropIndex('idx_indicator_lookup');
            $table->dropColumn(['rsi_14', 'bb_upper', 'bb_middle', 'bb_lower']);
        });
    }
};
