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
        Schema::table('sql_logs', function (Blueprint $table) {
            $table->boolean('cached_data')->default(false)->after('stack_trace');
            $table->index('cached_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sql_logs', function (Blueprint $table) {
            $table->dropIndex(['cached_data']);
            $table->dropColumn('cached_data');
        });
    }
};
