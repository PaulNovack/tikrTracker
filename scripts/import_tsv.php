<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function parseDec(?string $v): ?string
{
    $v = str_replace(',', '', trim($v ?? ''));

    return ($v === '' || $v === '-') ? null : $v;
}
function parseTs(?string $v): ?string
{
    $v = trim($v ?? '');
    if ($v === '' || $v === '-') {
        return null;
    }
    $ts = strtotime($v);

    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

$file = 'storage/app/real_alpaca_orders.tsv';
$fh = fopen($file, 'r');
$rows = [];
$inserted = 0;
$skipped = 0;

while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $cols = explode("\t", $line);
    if (count($cols) < 14) {
        continue;
    }

    $id = $cols[0];
    if (DB::table('real_alpaca_orders')->where('id', $id)->exists()) {
        $skipped++;

        continue;
    }

    $rows[] = [
        'id' => $id, 'symbol' => $cols[1], 'order_description' => $cols[2],
        'type' => $cols[3], 'side' => $cols[4],
        'qty' => parseDec($cols[5]), 'filled_qty' => parseDec($cols[6]),
        'currency' => $cols[7] ?? 'USD',
        'avg_fill_price' => parseDec($cols[8]), 'limit_price' => parseDec($cols[9]),
        'stop_price' => parseDec($cols[10]), 'total_amount' => parseDec($cols[11]),
        'status' => $cols[12], 'source' => $cols[13] ?? null,
        'submitted_at' => parseTs($cols[14] ?? null),
        'filled_at' => parseTs($cols[15] ?? null),
        'expires_at' => parseTs($cols[16] ?? null),
    ];

    if (count($rows) >= 100) {
        DB::table('real_alpaca_orders')->insert($rows);
        $inserted += count($rows);
        $rows = [];
    }
}
if ($rows !== []) {
    DB::table('real_alpaca_orders')->insert($rows);
    $inserted += count($rows);
}
fclose($fh);
echo "Inserted: {$inserted}, Skipped: {$skipped}\n";
echo 'Total: '.DB::table('real_alpaca_orders')->count()."\n";
