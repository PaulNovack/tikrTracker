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
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->string('skipped_reason', 80)->nullable()->after('blacklisted');
            $table->timestamp('skipped_at')->nullable()->after('skipped_reason');
            $table->decimal('skip_price', 20, 8)->nullable()->after('skipped_at');
            $table->index('skipped_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropIndex(['skipped_reason']);
            $table->dropColumn(['skipped_reason', 'skipped_at', 'skip_price']);
        });
    }
};
