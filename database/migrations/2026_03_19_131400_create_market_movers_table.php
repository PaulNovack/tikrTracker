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
        Schema::create('market_movers', function (Blueprint $table) {
            $table->id();
            $table->date('trading_date')->unique();
            $table->integer('bars_4pct_plus')->default(0);
            $table->integer('bars_5pct_plus')->default(0);
            $table->integer('bars_10pct_plus')->default(0);
            $table->decimal('max_gain', 8, 2)->default(0);
            $table->integer('strength')->default(0);
            $table->string('label', 20); // STRONG, MODERATE, WEAK
            $table->json('movers'); // Array of {symbol, gain_pct}
            $table->timestamps();

            $table->index('trading_date');
            $table->index('strength');
            $table->index('label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_movers');
    }
};
