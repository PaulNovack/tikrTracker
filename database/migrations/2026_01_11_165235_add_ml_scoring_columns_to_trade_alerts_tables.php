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
        // Add ML scoring columns to trade_alerts
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->decimal('ml_win_prob', 10, 6)->nullable()->index();
            $table->timestamp('ml_scored_at')->nullable()->index();
            $table->string('ml_model_version', 64)->nullable()->index();
        });

        // Add ML scoring columns to trade_alerts_unfiltered
        Schema::table('trade_alerts_unfiltered', function (Blueprint $table) {
            $table->decimal('ml_win_prob', 10, 6)->nullable()->index();
            $table->timestamp('ml_scored_at')->nullable()->index();
            $table->string('ml_model_version', 64)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropIndex(['ml_win_prob']);
            $table->dropIndex(['ml_scored_at']);
            $table->dropIndex(['ml_model_version']);
            $table->dropColumn(['ml_win_prob', 'ml_scored_at', 'ml_model_version']);
        });

        Schema::table('trade_alerts_unfiltered', function (Blueprint $table) {
            $table->dropIndex(['ml_win_prob']);
            $table->dropIndex(['ml_scored_at']);
            $table->dropIndex(['ml_model_version']);
            $table->dropColumn(['ml_win_prob', 'ml_scored_at', 'ml_model_version']);
        });
    }
};
