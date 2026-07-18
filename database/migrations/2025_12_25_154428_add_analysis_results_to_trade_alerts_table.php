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
            if (! Schema::hasColumn('trade_alerts', 'exit_price')) {
                $table->decimal('exit_price', 12, 4)->nullable()->after('targets');
            }
            if (! Schema::hasColumn('trade_alerts', 'exit_ts_est')) {
                $table->timestamp('exit_ts_est')->nullable()->after('exit_price');
            }
            if (! Schema::hasColumn('trade_alerts', 'exit_reason')) {
                $table->string('exit_reason', 20)->nullable()->after('exit_ts_est')->index();
            }
            if (! Schema::hasColumn('trade_alerts', 'pnl_percent')) {
                $table->decimal('pnl_percent', 8, 2)->nullable()->after('exit_reason')->index();
            }
            if (! Schema::hasColumn('trade_alerts', 'pnl_dollar')) {
                $table->decimal('pnl_dollar', 12, 4)->nullable()->after('pnl_percent');
            }
            if (! Schema::hasColumn('trade_alerts', 'r_multiple')) {
                $table->decimal('r_multiple', 8, 2)->nullable()->after('pnl_dollar')->index();
            }
            if (! Schema::hasColumn('trade_alerts', 'target_hit')) {
                $table->string('target_hit', 10)->nullable()->after('r_multiple');
            }
            if (! Schema::hasColumn('trade_alerts', 'analyzed')) {
                $table->boolean('analyzed')->default(false)->after('target_hit')->index();
            }
            if (! Schema::hasColumn('trade_alerts', 'analyzed_at')) {
                $table->timestamp('analyzed_at')->nullable()->after('analyzed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'exit_price',
                'exit_ts_est',
                'exit_reason',
                'pnl_percent',
                'pnl_dollar',
                'r_multiple',
                'target_hit',
                'analyzed',
                'analyzed_at',
            ]);
        });
    }
};
