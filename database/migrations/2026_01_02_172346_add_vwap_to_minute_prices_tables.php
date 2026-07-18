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
            $table->decimal('vwap', 12, 6)->nullable()->after('price');
        });

        Schema::table('one_minute_prices', function (Blueprint $table) {
            $table->decimal('vwap', 12, 6)->nullable()->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropColumn('vwap');
        });

        Schema::table('one_minute_prices', function (Blueprint $table) {
            $table->dropColumn('vwap');
        });
    }
};
