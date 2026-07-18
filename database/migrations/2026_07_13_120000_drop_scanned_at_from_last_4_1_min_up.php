<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('last_4_1_min_up', function (Blueprint $table) {
            $table->dropIndex('l4u_scanned_at_idx');
            $table->dropColumn('scanned_at');
        });
    }

    public function down(): void
    {
        Schema::table('last_4_1_min_up', function (Blueprint $table) {
            $table->dateTime('scanned_at')->nullable();
            $table->index('scanned_at', 'l4u_scanned_at_idx');
        });
    }
};
