#!/usr/bin/env python3
"""Rewrite broken getSpyMovement30m methods in 5 scanner files."""
import re, os

files = [
    'FiveMinuteSignalScannerV101_0.php',
    'FiveMinuteSignalScannerV1600_0.php',
    'FiveMinuteSignalScannerV27_0.php',
    'FiveMinuteSignalScannerV3000_0.php',
    'FiveMinuteSignalScannerV35_0.php',
]

base = 'app/Services/Trading'

new_method = '''    protected function getSpyMovement30m(string $asOfTsEst, int $moveBars): float
    {
        $benchmarkSymbol = config('trading.market_benchmark_symbol', 'QQQM');
        $sql = '
SELECT
  price AS last_close,
  LAG(price, ?) OVER (ORDER BY ts_est) AS prev_close
FROM five_minute_prices
WHERE symbol = ?
  AND ts_est <= ?
ORDER BY ts_est ASC
';
        $rows = $this->dbSelect($sql, [$moveBars, $benchmarkSymbol, $asOfTsEst]);
        if (! $rows) {
            return 0.0;
        }
        $last = end($rows);
        if (! $last || empty($last->prev_close)) {
            return 0.0;
        }
        $prev = (float) $last->prev_close;
        $lc = (float) $last->last_close;
        if ($prev <= 0) {
            return 0.0;
        }
        return (($lc - $prev) / $prev) * 100.0;
    }'''

for f in files:
    path = os.path.join(base, f)
    with open(path, 'r') as fh:
        c = fh.read()

    pattern = r'(    protected function getSpyMovement30m\(string \$asOfTsEst, int \$moveBars\): float\s*\{).*?(    \}\n)'
    c = re.sub(pattern, new_method + '\n', c, flags=re.DOTALL)

    with open(path, 'w') as fh:
        fh.write(c)
    print(f'{f}: OK')
