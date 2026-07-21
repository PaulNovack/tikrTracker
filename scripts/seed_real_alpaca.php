<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tsv = <<<'TSV'
3279fe57-c29f-4965-bd23-068ca5013082	VSAT	Stop @ $72.83	stop	sell	103.00	103.00	USD	72.79	-	72.83	7497.37	filled	access_key	Jul 21, 2026, 12:53:06 PM	Jul 21, 2026, 01:09:54 PM	Oct 19, 2026, 03:00:00 PM
54ad24c6-93ae-4d28-b43c-c79dd2681b7d	VSAT	Stop @ $72.70	stop	sell	103.00	0.00	USD	-	-	72.70	-	canceled	access_key	Jul 21, 2026, 12:51:40 PM	-	Oct 19, 2026, 03:00:00 PM
692aac23-33b4-4f35-962c-42cea9eb589c	VSAT	Stop @ $72.61	stop	sell	103.00	0.00	USD	-	-	72.61	-	canceled	access_key	Jul 21, 2026, 12:48:08 PM	-	Oct 19, 2026, 03:00:00 PM
11bfa784-584e-4897-918f-0b706dde6f51	VSAT	Stop @ $72.57	stop	sell	103.00	0.00	USD	-	-	72.57	-	canceled	access_key	Jul 21, 2026, 12:47:34 PM	-	Oct 19, 2026, 03:00:00 PM
6a76cd63-1a6b-4fdf-968b-c7cfcf8a00ab	FCEL	Stop @ $21.80	stop	sell	871.00	871.00	USD	21.78	-	21.80	18970.38	filled	access_key	Jul 21, 2026, 12:41:44 PM	Jul 21, 2026, 12:58:20 PM	Oct 19, 2026, 03:00:00 PM
19ef9283-e639-4125-9eaf-b710581726ac	FCEL	Stop @ $21.77	stop	sell	871.00	0.00	USD	-	-	21.77	-	canceled	access_key	Jul 21, 2026, 12:36:44 PM	-	Oct 19, 2026, 03:00:00 PM
a26c577b-b5f8-404e-8687-0073975da820	FCEL	Stop @ $21.76	stop	sell	871.00	0.00	USD	-	-	21.76	-	canceled	access_key	Jul 21, 2026, 12:29:05 PM	-	Oct 19, 2026, 03:00:00 PM
8165cdc4-faa4-4fb9-bc62-5a6cf8bf4557	FCEL	Stop @ $21.75	stop	sell	871.00	0.00	USD	-	-	21.75	-	canceled	access_key	Jul 21, 2026, 12:23:06 PM	-	Oct 19, 2026, 03:00:00 PM
97387549-9604-4dc9-b1dd-2eb397a8c107	FCEL	Stop @ $21.61	stop	sell	871.00	0.00	USD	-	-	21.61	-	canceled	access_key	Jul 21, 2026, 12:05:05 PM	-	Oct 19, 2026, 03:00:00 PM
4e9722a7-197b-4fe3-9872-9d0a6d1e98d4	FCEL	Stop @ $21.57	stop	sell	871.00	0.00	USD	-	-	21.57	-	canceled	access_key	Jul 21, 2026, 12:04:05 PM	-	Oct 19, 2026, 03:00:00 PM
15cfe3a0-97f1-4eac-8e06-f03aefcc886c	FCEL	Stop @ $21.55	stop	sell	871.00	0.00	USD	-	-	21.55	-	canceled	access_key	Jul 21, 2026, 12:01:51 PM	-	Oct 19, 2026, 03:00:00 PM
a6947e98-b485-45ef-81e5-0896f1dc1d25	SBSW	Stop @ $8.51	stop	sell	749.00	0.00	USD	-	-	8.51	-	new	access_key	Jul 21, 2026, 11:59:09 AM	-	Oct 19, 2026, 03:00:00 PM
6af9d3b3-1079-46a8-974d-7654e509147c	FCEL	Stop @ $21.47	stop	sell	871.00	0.00	USD	-	-	21.47	-	canceled	access_key	Jul 21, 2026, 11:59:07 AM	-	Oct 19, 2026, 03:00:00 PM
6209684c-d711-4c7f-9dd2-c14046c464e2	SBSW	Stop @ $8.50	stop	sell	749.00	0.00	USD	-	-	8.50	-	canceled	access_key	Jul 21, 2026, 11:58:08 AM	-	Oct 19, 2026, 03:00:00 PM
1b650e75-6b05-4346-904f-8ffcd6490937	SBSW	Stop @ $8.49	stop	sell	749.00	0.00	USD	-	-	8.49	-	canceled	access_key	Jul 21, 2026, 11:54:06 AM	-	Oct 19, 2026, 03:00:00 PM
2e57c84a-ff9b-4052-8bd6-d8601351e1b4	FCEL	Stop @ $21.39	stop	sell	871.00	0.00	USD	-	-	21.39	-	canceled	access_key	Jul 21, 2026, 11:51:40 AM	-	Oct 19, 2026, 03:00:00 PM
d6591da3-566c-4bc8-a2f9-ce9ec776c6b1	SOC	Stop @ $4.33	stop	sell	22.00	22.00	USD	4.33	-	4.33	95.26	filled	access_key	Jul 21, 2026, 11:36:36 AM	Jul 21, 2026, 11:55:30 AM	Oct 19, 2026, 03:00:00 PM
1bd63894-9d25-48f3-8136-69bde0359c00	SOC	Market	market	sell	1076.00	1054.00	USD	4.35	-	-	4584.90	canceled	access_key	Jul 21, 2026, 11:35:03 AM	Jul 21, 2026, 11:35:05 AM	Oct 19, 2026, 03:00:00 PM
9c429f0d-adcf-40da-942c-c58a4050012c	VSAT	Stop @ $69.91	stop	sell	103.00	0.00	USD	-	-	69.91	-	canceled	access_key	Jul 21, 2026, 11:34:09 AM	-	Oct 19, 2026, 03:00:00 PM
dad69fc6-9a58-4196-8b50-9a8e7125647c	FCEL	Stop @ $21.09	stop	sell	871.00	0.00	USD	-	-	21.09	-	canceled	access_key	Jul 21, 2026, 11:34:07 AM	-	Oct 19, 2026, 03:00:00 PM
abaef0ba-5304-4359-a49b-16d707fe6810	CRCL	Stop @ $70.04	stop	sell	353.00	353.00	USD	70.177734	-	70.04	24772.74	filled	access_key	Jul 21, 2026, 11:34:05 AM	Jul 21, 2026, 11:44:18 AM	Oct 19, 2026, 03:00:00 PM
32f82a69-d74a-4b0b-9f5e-c0b9c216fbbd	VSAT	Limit @ $72.43	limit	buy	103.00	103.00	USD	72.39	72.43	-	7456.17	filled	access_key	Jul 21, 2026, 11:33:56 AM	Jul 21, 2026, 11:34:00 AM	Jul 21, 2026, 03:00:00 PM
207b050e-866f-4e84-86ca-164c7c9d87de	CRCL	Limit @ $70.79	limit	buy	353.00	353.00	USD	70.75	70.79	-	24974.75	filled	access_key	Jul 21, 2026, 11:33:42 AM	Jul 21, 2026, 11:33:42 AM	Jul 21, 2026, 03:00:00 PM
ba9553c3-130a-473e-9541-5838e6a8bd1f	FCEL	Limit @ $21.31	limit	buy	871.00	871.00	USD	21.30	21.31	-	18552.30	filled	access_key	Jul 21, 2026, 11:33:34 AM	Jul 21, 2026, 11:33:37 AM	Jul 21, 2026, 03:00:00 PM
2308a46f-7f41-466a-97ae-619798413fe8	SMCI	Stop @ $25.30	stop	sell	986.00	986.00	USD	25.30	-	25.30	24945.80	filled	access_key	Jul 21, 2026, 11:31:48 AM	Jul 21, 2026, 11:33:26 AM	Oct 19, 2026, 03:00:00 PM
TSV;

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

$lines = explode("\n", trim($tsv));
$rows = [];
foreach ($lines as $line) {
    $cols = explode("\t", $line);
    if (count($cols) < 14) {
        continue;
    }
    $rows[] = [
        'id' => $cols[0],
        'symbol' => $cols[1],
        'order_description' => $cols[2],
        'type' => $cols[3],
        'side' => $cols[4],
        'qty' => parseDec($cols[5]),
        'filled_qty' => parseDec($cols[6]),
        'currency' => $cols[7] ?? 'USD',
        'avg_fill_price' => parseDec($cols[8]),
        'limit_price' => parseDec($cols[9]),
        'stop_price' => parseDec($cols[10]),
        'total_amount' => parseDec($cols[11]),
        'status' => $cols[12],
        'source' => $cols[13] ?? null,
        'submitted_at' => parseTs($cols[14] ?? null),
        'filled_at' => parseTs($cols[15] ?? null),
        'expires_at' => parseTs($cols[16] ?? null),
    ];
}

// Chunk insert
foreach (array_chunk($rows, 100) as $chunk) {
    DB::table('real_alpaca_orders')->insert($chunk);
}
echo 'Inserted '.count($rows)." rows.\n";
