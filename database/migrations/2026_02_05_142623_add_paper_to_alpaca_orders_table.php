<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('alpaca_orders', function (Blueprint $table) {
            $table->boolean('paper')
                ->default((bool) config('alpaca.paper_trading', true))
                ->after('account_id');
        });

        DB::table('alpaca_orders')
            ->whereNull('paper')
            ->update(['paper' => (bool) config('alpaca.paper_trading', true)]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alpaca_orders', function (Blueprint $table) {
            $table->dropColumn('paper');
        });
    }
};
