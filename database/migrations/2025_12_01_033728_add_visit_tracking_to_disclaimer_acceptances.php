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
        Schema::table('disclaimer_acceptances', function (Blueprint $table) {
            $table->integer('root_page_visits')->default(0)->after('access_count');
            $table->timestamp('first_visit_at')->nullable()->after('root_page_visits');
            $table->boolean('time_threshold_triggered')->default(false)->after('first_visit_at');
            $table->timestamp('time_threshold_triggered_at')->nullable()->after('time_threshold_triggered');

            // Add index for performance
            $table->index(['ip_address', 'root_page_visits', 'time_threshold_triggered'], 'disc_visit_tracking_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('disclaimer_acceptances', function (Blueprint $table) {
            $table->dropIndex('disc_visit_tracking_idx');
            $table->dropColumn([
                'root_page_visits',
                'first_visit_at',
                'time_threshold_triggered',
                'time_threshold_triggered_at',
            ]);
        });
    }
};
