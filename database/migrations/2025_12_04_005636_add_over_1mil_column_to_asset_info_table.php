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
        Schema::table('asset_info', function (Blueprint $table) {
            $table->boolean('over_1mil')->default(false)->after('asset_type');
            $table->index('over_1mil');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_info', function (Blueprint $table) {
            $table->dropIndex(['over_1mil']);
            $table->dropColumn('over_1mil');
        });
    }
};
