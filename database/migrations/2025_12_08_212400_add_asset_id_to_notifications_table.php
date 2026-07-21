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
            $table->bigInteger('asset_id')->nullable()->after('user_id');
            $table->index('asset_id');
            // Foreign key constraint can be added if needed
            // $table->foreign('asset_id')->references('id')->on('asset_info');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['asset_id']);
            $table->dropColumn('asset_id');
        });
    }
};
