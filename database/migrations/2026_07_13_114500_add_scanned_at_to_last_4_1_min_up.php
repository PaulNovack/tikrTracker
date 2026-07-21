<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('last_4_1_min_up', function (Blueprint $table) {
            if (! Schema::hasColumn('last_4_1_min_up', 'scanned_at')) {
                $table->dateTime('scanned_at')->nullable()->after('total_pct_change');
            }

            if (! $this->indexExists('last_4_1_min_up', 'l4u_scanned_at_idx')) {
                $table->index('scanned_at', 'l4u_scanned_at_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('last_4_1_min_up', function (Blueprint $table) {
            if ($this->indexExists('last_4_1_min_up', 'l4u_scanned_at_idx')) {
                $table->dropIndex('l4u_scanned_at_idx');
            }

            if (Schema::hasColumn('last_4_1_min_up', 'scanned_at')) {
                $table->dropColumn('scanned_at');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }
};
