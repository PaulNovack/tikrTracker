<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v1500.0 Opening Range Breakout scanner.
 *
 * Pipeline O uses Redis for symbol scanning (scanSymbol) in the event-driven
 * bar-events:consume path, and falls back to SQL for the batch scan.
 *
 * Since V1500_0 is a standalone scanner (not extending AbstractSignalScanner),
 * we provide a full doScan() override that checks shouldUseRedis() and falls
 * back to parent SQL when disabled.
 */
class FiveMinuteSignalScannerV1500_0Redis extends FiveMinuteSignalScannerV1500_0
{
    use UsesRedisForScanning;
}
