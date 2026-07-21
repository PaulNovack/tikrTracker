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
        Schema::create('trade_alert_analysis', function (Blueprint $table) {
            $table->id();

            // Link to trade alert
            $table->unsignedBigInteger('trade_alert_id')->index();
            $table->foreign('trade_alert_id')->references('id')->on('trade_alerts')->onDelete('cascade');

            // Alert context
            $table->string('symbol', 20)->index();
            $table->string('asset_type', 20)->default('stock');
            $table->string('pipeline_version', 20)->index();
            $table->date('trading_date_est')->index();
            $table->timestamp('signal_ts_est')->index();

            // Trade outcome (from trade_alerts table)
            $table->boolean('is_winner')->nullable()->index()->comment('TRUE if pnl_percent > 0');
            $table->decimal('pnl_percent', 8, 2)->nullable()->index()->comment('P&L percentage from trade_alerts');
            $table->decimal('pnl_dollar', 12, 4)->nullable()->comment('P&L dollar amount from trade_alerts');
            $table->string('exit_reason', 20)->nullable()->index()->comment('Exit reason from trade_alerts');
            $table->decimal('r_multiple', 8, 2)->nullable()->comment('Risk multiple (R) from trade_alerts');

            // Historical 5-minute data (100 minutes back at 10-minute intervals)
            // Each column stores GROUP_CONCAT of values at that interval
            // Format: "price,ema9,ema21,rsi_14,volume,atr_pct,vwap"
            $table->text('data_100m_back')->nullable()->comment('5m data 100 minutes before signal');
            $table->text('data_90m_back')->nullable()->comment('5m data 90 minutes before signal');
            $table->text('data_80m_back')->nullable()->comment('5m data 80 minutes before signal');
            $table->text('data_70m_back')->nullable()->comment('5m data 70 minutes before signal');
            $table->text('data_60m_back')->nullable()->comment('5m data 60 minutes before signal');
            $table->text('data_50m_back')->nullable()->comment('5m data 50 minutes before signal');
            $table->text('data_40m_back')->nullable()->comment('5m data 40 minutes before signal');
            $table->text('data_30m_back')->nullable()->comment('5m data 30 minutes before signal');
            $table->text('data_20m_back')->nullable()->comment('5m data 20 minutes before signal');
            $table->text('data_10m_back')->nullable()->comment('5m data 10 minutes before signal');
            $table->text('data_signal')->nullable()->comment('5m data at signal time');

            $table->timestamps();

            // Composite index for efficient lookups
            $table->index(['pipeline_version', 'trading_date_est']);
            $table->index(['symbol', 'trading_date_est']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_alert_analysis');
    }
};
