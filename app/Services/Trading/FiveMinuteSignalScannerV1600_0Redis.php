<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v1600.0 signal scanner.
 */
class FiveMinuteSignalScannerV1600_0Redis extends FiveMinuteSignalScannerV1600_0
{
    use UsesRedisForScanning;
}
