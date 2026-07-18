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
            $table->decimal('ml_live_win_prob', 8, 6)->nullable()->after('ml_model_version')
                ->comment('ML win probability re-scored at order placement time using the current 1-min bar');
            $table->timestamp('ml_live_scored_at')->nullable()->after('ml_live_win_prob')
                ->comment('When the live re-score was performed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropColumn(['ml_live_win_prob', 'ml_live_scored_at']);
        });
    }
};
