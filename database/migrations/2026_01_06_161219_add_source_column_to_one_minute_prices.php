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
        Schema::table('one_minute_prices', function (Blueprint $table) {
            $table->string('source', 20)->default('yfinance')->after('symbol');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('one_minute_prices', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
