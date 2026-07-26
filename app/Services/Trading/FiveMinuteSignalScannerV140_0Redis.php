<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v140.0 signal scanner.
 */
class FiveMinuteSignalScannerV140_0Redis extends FiveMinuteSignalScannerV140_0
{
    use UsesRedisForScanning;
}
