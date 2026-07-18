<?php

namespace App\Trading;

class SymbolBlacklist
{
    /**
     * Symbols with 3+ losses, zero wins, and at least one ML score >= 0.70
     * These symbols indicate ML model failure - high probability predictions that still lost
     */
    private static array $blacklist = [
        'GSAT', 'LQDA', 'BHVN', 'BTU', 'WGMI', 'TSLG', 'AMSC', 'SMCZ', 'MBOT', 'SEZL',
        'BNKK', 'MBC', 'ABCL', 'AUGO', 'FWRD', 'ZVRA', 'CTEV', 'PCRX', 'EVLV', 'HRTG',
        'KYIV', 'APGE', 'NESR', 'SLDE', 'AZTA', 'AMZU', 'SDGR', 'MULL', 'LB', 'BEAM',
        'HNRG', 'BLNK', 'INBX', 'CECO', 'BBW', 'SQM', 'HP', 'RIVN', 'SDRL', 'ASX',
        'SITM', 'WERN', 'IMCR', 'SMMT', 'CBRL', 'NVAX', 'FTAI', 'TREE', 'CRI', 'WIX',
        'BRKR', 'AMN', 'LMB', 'WWW', 'CTRI', 'LGND', 'VERX', 'DLO', 'AEO', 'UNHG',
        'GEO', 'UNIT', 'ARLO', 'NBR', 'OS', 'KALA', 'ODD', 'PHVS', 'SHO', 'LCID',
        'ENR', 'STRL', 'NWL', 'CCHH',
    ];

    public static function isBlacklisted(string $symbol): bool
    {
        return in_array(strtoupper($symbol), self::$blacklist, true);
    }

    public static function getBlacklist(): array
    {
        return self::$blacklist;
    }

    public static function count(): int
    {
        return count(self::$blacklist);
    }
}
