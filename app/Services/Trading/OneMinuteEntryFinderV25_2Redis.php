<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v25.2 one-minute entry finder.
 */
class OneMinuteEntryFinderV25_2Redis extends OneMinuteEntryFinderV25_2
{
    use UsesRedisForEntryFinding;
}
