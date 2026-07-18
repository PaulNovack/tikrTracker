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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('trader')->after('email');
            $table->index('role');
        });

        // Set paul.novack@gmail.com as admin
        DB::table('users')
            ->where('email', 'paul.novack@gmail.com')
            ->update(['role' => 'admin']);

        // Set guest user
        DB::table('users')
            ->where('email', 'guest@tikrtracker.com')
            ->update(['role' => 'guest']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
