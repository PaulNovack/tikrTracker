<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v400.0 signal scanner.
 */
class FiveMinuteSignalScannerV400_0Redis extends FiveMinuteSignalScannerV400_0
{
    use UsesRedisForScanning;
}
