<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v900.1 signal scanner.
 */
class FiveMinuteSignalScannerV900_1Redis extends FiveMinuteSignalScannerV900_1
{
    use UsesRedisForScanning;
}
