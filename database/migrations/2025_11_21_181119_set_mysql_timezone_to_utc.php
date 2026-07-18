<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Set MySQL timezone to UTC for current session
        // The PDO INIT_COMMAND in config/database.php handles session for each connection
        try {
            DB::statement("SET GLOBAL time_zone='+00:00'");
        } catch (\Exception $e) {
            // Silently fail if we don't have SUPER privilege
            // The connection-level timezone setting will handle it
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to system timezone
        try {
            DB::statement("SET GLOBAL time_zone='SYSTEM'");
        } catch (\Exception $e) {
            // Silently fail if we don't have SUPER privilege
        }
    }
};
