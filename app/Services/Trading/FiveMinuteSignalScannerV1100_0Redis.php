<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v1100.0 signal scanner.
 */
class FiveMinuteSignalScannerV1100_0Redis extends FiveMinuteSignalScannerV1100_0
{
    use UsesRedisForScanning;
}
