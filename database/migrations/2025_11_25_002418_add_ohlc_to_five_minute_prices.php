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
            $table->decimal('open', 20, 8)->nullable()->after('price');
            $table->decimal('high', 20, 8)->nullable()->after('open');
            $table->decimal('low', 20, 8)->nullable()->after('high');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropColumn(['open', 'high', 'low']);
        });
    }
};
