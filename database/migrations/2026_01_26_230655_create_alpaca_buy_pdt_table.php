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
        Schema::create('alpaca_buy_pdt', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20)->index();
            $table->timestamp('utc_time')->index();
            $table->dateTime('est_time')->index();
            $table->date('est_day')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alpaca_buy_pdt');
    }
};
