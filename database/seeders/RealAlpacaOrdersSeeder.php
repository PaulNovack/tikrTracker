<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RealAlpacaOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $file = storage_path('app/real_alpaca_orders.tsv');
        if (! file_exists($file)) {
            $this->command?->error("File not found: {$file}");

            return;
        }

        $fh = fopen($file, 'r');
        fgets($fh); // skip header
        $chunk = [];
        $count = 0;

        while (($line = fgetcsv($fh, 0, "\t")) !== false) {
            if (count($line) < 14) {
                continue;
            }

            $chunk[] = [
                'id' => $line[0],
                'symbol' => $line[1],
                'order_description' => $line[2] ?? null,
                'type' => $line[3] ?? null,
                'side' => $line[4],
                'qty' => $this->parseDecimal($line[5]),
                'filled_qty' => $this->parseDecimal($line[6]),
                'currency' => $line[7] ?? 'USD',
                'avg_fill_price' => $this->parseNullableDecimal($line[8]),
                'limit_price' => $this->parseNullableDecimal($line[9]),
                'stop_price' => $this->parseNullableDecimal($line[10]),
                'total_amount' => $this->parseNullableDecimal($line[11]),
                'status' => $line[12],
                'source' => $line[13] ?? null,
                'submitted_at' => $this->parseTimestamp($line[14] ?? null),
                'filled_at' => $this->parseTimestamp($line[15] ?? null),
                'expires_at' => $this->parseTimestamp($line[16] ?? null),
                'created_at' => now(),
            ];
            $count++;

            if (count($chunk) >= 100) {
                DB::table('real_alpaca_orders')->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            DB::table('real_alpaca_orders')->insert($chunk);
        }
        fclose($fh);

        $this->command?->info("Inserted {$count} orders into real_alpaca_orders.");
    }

    private function parseDecimal(string $value): string
    {
        return str_replace(',', '', $value);
    }

    private function parseNullableDecimal(string $value): ?string
    {
        $v = trim($value);
        if ($v === '' || $v === '-') {
            return null;
        }

        return str_replace(',', '', $v);
    }

    private function parseTimestamp(?string $value): ?string
    {
        if (! $value || trim($value) === '' || trim($value) === '-') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
