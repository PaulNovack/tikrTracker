<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v103.0 one-minute entry finder.
 */
class OneMinuteEntryFinderV103_0Redis extends OneMinuteEntryFinderV103_0
{
    use UsesRedisForEntryFinding;
}
