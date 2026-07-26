<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v25.2 signal scanner.
 */
class FiveMinuteSignalScannerV25_2Redis extends FiveMinuteSignalScannerV25_2
{
    use UsesRedisForScanning;
}
