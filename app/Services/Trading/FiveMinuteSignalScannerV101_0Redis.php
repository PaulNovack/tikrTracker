<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v101.0 signal scanner.
 */
class FiveMinuteSignalScannerV101_0Redis extends FiveMinuteSignalScannerV101_0
{
    use UsesRedisForScanning;
}
