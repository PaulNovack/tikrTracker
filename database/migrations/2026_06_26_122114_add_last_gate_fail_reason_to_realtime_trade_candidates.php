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
        Schema::table('realtime_trade_candidates', function (Blueprint $table) {
            $table->string('last_gate_fail_reason')->nullable()->after('rejection_reason')->comment('Last gate failure reason before expiration or rejection');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('realtime_trade_candidates', function (Blueprint $table) {
            $table->dropColumn('last_gate_fail_reason');
        });
    }
};
