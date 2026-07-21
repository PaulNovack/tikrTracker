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
            $table->decimal('change_from_open', 10, 4)->nullable()->after('volume');
            $table->decimal('relative_volume', 10, 4)->nullable()->after('change_from_open');

            $table->index(['trading_date_est', 'change_from_open']);
            $table->index(['trading_date_est', 'relative_volume']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropIndex(['trading_date_est', 'change_from_open']);
            $table->dropIndex(['trading_date_est', 'relative_volume']);
            $table->dropColumn(['change_from_open', 'relative_volume']);
        });
    }
};
