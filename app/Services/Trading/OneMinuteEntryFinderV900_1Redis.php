<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v900.1 one-minute entry finder.
 */
class OneMinuteEntryFinderV900_1Redis extends OneMinuteEntryFinderV900_1
{
    use UsesRedisForEntryFinding;
}
