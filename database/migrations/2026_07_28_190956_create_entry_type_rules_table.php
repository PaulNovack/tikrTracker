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
        Schema::create('entry_type_rules', function (Blueprint $table) {
            $table->id();
            $table->string('entry_type', 50);
            $table->integer('priority')->default(0);
            $table->json('rules');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique('entry_type');
        });

        // Seed default rules
        DB::table('entry_type_rules')->insert([
            [
                'entry_type' => 'VWAP_RECLAIM_STRONG',
                'priority' => 1,
                'rules' => json_encode([
                    ['gate' => 'above_vwap', 'op' => '>=', 'value' => 1],
                    ['gate' => 'vol_ratio_1m', 'op' => '>=', 'value' => 2.0],
                    ['gate' => 'body_pct', 'op' => '>=', 'value' => 0.03],
                    ['gate' => 'above_vwap_pct', 'op' => '<=', 'value' => 1.5],
                ]),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entry_type' => 'VWAP_RECLAIM',
                'priority' => 2,
                'rules' => json_encode([
                    ['gate' => 'above_vwap', 'op' => '>=', 'value' => 1],
                    ['gate' => 'body_pct', 'op' => '>=', 'value' => 0.02],
                    ['gate' => 'above_vwap_pct', 'op' => '<=', 'value' => 3.0],
                ]),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entry_type' => 'ORB_RETEST',
                'priority' => 3,
                'rules' => json_encode([
                    ['gate' => 'vol_ratio_1m', 'op' => '>=', 'value' => 2.5],
                    ['gate' => 'ema9_above_ema21_1m', 'op' => '>=', 'value' => 1],
                ]),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entry_type' => 'EMA9_PULLBACK',
                'priority' => 4,
                'rules' => json_encode([
                    ['gate' => 'ema9_above_ema21_1m', 'op' => '>=', 'value' => 1],
                    ['gate' => 'vol_ratio_1m', 'op' => '>=', 'value' => 0.8],
                    ['gate' => 'close_position', 'op' => '>=', 'value' => 0.40],
                ]),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entry_type' => 'SIMPLE_MOMENTUM',
                'priority' => 5,
                'rules' => json_encode([
                    ['gate' => 'move_30m_pct', 'op' => '>=', 'value' => 0],
                    ['gate' => 'vol_ratio_1m', 'op' => '>=', 'value' => 1.2],
                ]),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entry_type_rules');
    }
};
