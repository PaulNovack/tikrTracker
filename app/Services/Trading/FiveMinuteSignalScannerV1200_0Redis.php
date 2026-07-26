<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v1200.0 signal scanner.
 */
class FiveMinuteSignalScannerV1200_0Redis extends FiveMinuteSignalScannerV1200_0
{
    use UsesRedisForScanning;
}
