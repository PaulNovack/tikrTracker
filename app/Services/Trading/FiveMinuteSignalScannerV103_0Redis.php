<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v103.0 signal scanner.
 */
class FiveMinuteSignalScannerV103_0Redis extends FiveMinuteSignalScannerV103_0
{
    use UsesRedisForScanning;
}
