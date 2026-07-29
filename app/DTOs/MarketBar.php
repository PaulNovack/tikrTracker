<?php

namespace App\DTOs;

final readonly class MarketBar
{
    public function __construct(
        public string $symbol,
        public string $assetType,
        public string $timeframe,
        public int $epoch,
        public string $tsEst,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public float $volume,
        public ?float $vwap,
        public bool $isFinal,
        public ?float $ema9 = null,
        public ?float $ema21 = null,
        public ?int $aboveVwap = null,
        public ?float $rsi14 = null,
        public ?float $bbPosition = null,
    ) {}
}
