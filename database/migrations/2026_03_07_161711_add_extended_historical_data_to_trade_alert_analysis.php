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
        Schema::table('trade_alert_analysis', function (Blueprint $table) {
            // Add columns for 200 minutes back (extending from 100 to 200)
            $table->text('data_200m_back')->nullable()->after('r_multiple')->comment('5m data 200 minutes before signal');
            $table->text('data_190m_back')->nullable()->after('data_200m_back')->comment('5m data 190 minutes before signal');
            $table->text('data_180m_back')->nullable()->after('data_190m_back')->comment('5m data 180 minutes before signal');
            $table->text('data_170m_back')->nullable()->after('data_180m_back')->comment('5m data 170 minutes before signal');
            $table->text('data_160m_back')->nullable()->after('data_170m_back')->comment('5m data 160 minutes before signal');
            $table->text('data_150m_back')->nullable()->after('data_160m_back')->comment('5m data 150 minutes before signal');
            $table->text('data_140m_back')->nullable()->after('data_150m_back')->comment('5m data 140 minutes before signal');
            $table->text('data_130m_back')->nullable()->after('data_140m_back')->comment('5m data 130 minutes before signal');
            $table->text('data_120m_back')->nullable()->after('data_130m_back')->comment('5m data 120 minutes before signal');
            $table->text('data_110m_back')->nullable()->after('data_120m_back')->comment('5m data 110 minutes before signal');
        });
    }

    public function down(): void
    {
        Schema::table('trade_alert_analysis', function (Blueprint $table) {
            $table->dropColumn([
                'data_200m_back',
                'data_190m_back',
                'data_180m_back',
                'data_170m_back',
                'data_160m_back',
                'data_150m_back',
                'data_140m_back',
                'data_130m_back',
                'data_120m_back',
                'data_110m_back',
            ]);
        });
    }
};
