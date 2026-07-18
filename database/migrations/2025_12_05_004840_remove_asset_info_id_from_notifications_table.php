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
        Schema::table('notifications', function (Blueprint $table) {
            // Check if foreign key exists before dropping
            if (DB::select("SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                           WHERE TABLE_NAME = 'notifications' 
                           AND CONSTRAINT_NAME = 'notifications_asset_info_id_foreign'")) {
                $table->dropForeign(['asset_info_id']);
            }

            // Check if index exists before dropping
            if (DB::select("SELECT * FROM INFORMATION_SCHEMA.STATISTICS 
                           WHERE TABLE_NAME = 'notifications' 
                           AND COLUMN_NAME = 'asset_info_id'")) {
                $table->dropIndex(['user_id', 'asset_info_id']);
            }

            // Check if column exists before dropping
            if (Schema::hasColumn('notifications', 'asset_info_id')) {
                $table->dropColumn('asset_info_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_info_id')->nullable()->after('user_id');
            $table->foreign('asset_info_id')->references('id')->on('asset_info')->onDelete('set null');
            $table->index(['user_id', 'asset_info_id']);
        });
    }
};
