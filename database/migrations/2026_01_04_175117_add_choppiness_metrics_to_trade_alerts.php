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
            $table->tinyInteger('five_min_directional_changes')->nullable()->after('vol_ratio');
            $table->decimal('five_min_green_bar_pct', 4, 1)->nullable()->after('five_min_directional_changes');
            $table->decimal('five_min_net_progress', 4, 3)->nullable()->after('five_min_green_bar_pct');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'five_min_directional_changes',
                'five_min_green_bar_pct',
                'five_min_net_progress',
            ]);
        });
    }
};
