<?php

use App\Services\Trading\TradeAlertWriterV1;

it('uses low liquidity percentage when high-risk slippage threshold is breached', function () {
    $rule = [
        'low_liquidity_pct' => 10.0,
        'medium_liquidity_pct' => 12.5,
        'high_liquidity_pct' => 15.0,
        'medium_risk_avg_slippage_pct' => 0.06,
        'medium_risk_worst_slippage_pct' => 0.80,
        'high_risk_avg_slippage_pct' => 0.12,
        'high_risk_worst_slippage_pct' => 1.50,
        'min_liquidity_pct' => 10.0,
        'max_liquidity_pct' => 20.0,
    ];

    $pct = TradeAlertWriterV1::determineLiquidityPctFromSlippageRule(
        $rule,
        10.0,
        100,
        0.07,
        1.70
    );

    expect($pct)->toBe(10.0);
});

it('uses medium liquidity percentage for medium slippage risk', function () {
    $rule = [
        'low_liquidity_pct' => 10.0,
        'medium_liquidity_pct' => 12.5,
        'high_liquidity_pct' => 15.0,
        'medium_risk_avg_slippage_pct' => 0.06,
        'medium_risk_worst_slippage_pct' => 0.80,
        'high_risk_avg_slippage_pct' => 0.12,
        'high_risk_worst_slippage_pct' => 1.50,
        'min_liquidity_pct' => 10.0,
        'max_liquidity_pct' => 20.0,
    ];

    $pct = TradeAlertWriterV1::determineLiquidityPctFromSlippageRule(
        $rule,
        10.0,
        120,
        0.07,
        0.70
    );

    expect($pct)->toBe(12.5);
});

it('uses high liquidity percentage when slippage risk is low', function () {
    $rule = [
        'low_liquidity_pct' => 10.0,
        'medium_liquidity_pct' => 12.5,
        'high_liquidity_pct' => 15.0,
        'medium_risk_avg_slippage_pct' => 0.06,
        'medium_risk_worst_slippage_pct' => 0.80,
        'high_risk_avg_slippage_pct' => 0.12,
        'high_risk_worst_slippage_pct' => 1.50,
        'min_liquidity_pct' => 10.0,
        'max_liquidity_pct' => 20.0,
    ];

    $pct = TradeAlertWriterV1::determineLiquidityPctFromSlippageRule(
        $rule,
        10.0,
        160,
        0.03,
        0.55
    );

    expect($pct)->toBe(15.0);
});
