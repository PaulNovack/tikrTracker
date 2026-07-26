<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v2000.0 signal scanner.
 */
class FiveMinuteSignalScannerV2000_0Redis extends FiveMinuteSignalScannerV2000_0
{
    use UsesRedisForScanning;
}
