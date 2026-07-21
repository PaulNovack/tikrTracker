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
            // Change all data columns from TEXT to DECIMAL(8,6)
            // This stores ratios like 1.025000 (max 99.999999, min 0.000001)
            $columns = [
                'data_200m_back', 'data_190m_back', 'data_180m_back', 'data_170m_back',
                'data_160m_back', 'data_150m_back', 'data_140m_back', 'data_130m_back',
                'data_120m_back', 'data_110m_back', 'data_100m_back', 'data_90m_back',
                'data_80m_back', 'data_70m_back', 'data_60m_back', 'data_50m_back',
                'data_40m_back', 'data_30m_back', 'data_20m_back', 'data_10m_back',
                'data_signal',
            ];

            foreach ($columns as $column) {
                $table->decimal($column, 8, 6)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('trade_alert_analysis', function (Blueprint $table) {
            $columns = [
                'data_200m_back', 'data_190m_back', 'data_180m_back', 'data_170m_back',
                'data_160m_back', 'data_150m_back', 'data_140m_back', 'data_130m_back',
                'data_120m_back', 'data_110m_back', 'data_100m_back', 'data_90m_back',
                'data_80m_back', 'data_70m_back', 'data_60m_back', 'data_50m_back',
                'data_40m_back', 'data_30m_back', 'data_20m_back', 'data_10m_back',
                'data_signal',
            ];

            foreach ($columns as $column) {
                $table->text($column)->nullable()->change();
            }
        });
    }
};
