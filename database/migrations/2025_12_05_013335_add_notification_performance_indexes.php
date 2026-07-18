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
        Schema::table('notifications', function (Blueprint $table) {
            // Composite index for pagination queries (user_id + created_at for ORDER BY)
            $table->index(['user_id', 'created_at'], 'idx_notifications_user_created');

            // Composite index for count queries with soft delete handling
            $table->index(['user_id', 'read', 'deleted_at'], 'idx_notifications_user_read_deleted');

            // Index for cleanup operations (created_at for date-based cleanup)
            $table->index(['created_at', 'deleted_at'], 'idx_notifications_created_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_user_created');
            $table->dropIndex('idx_notifications_user_read_deleted');
            $table->dropIndex('idx_notifications_created_deleted');
        });
    }
};
