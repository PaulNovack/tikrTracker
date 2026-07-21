<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use OpenAI;

class FetchNewsPositiveTickers extends Command
{
    protected $signature = 'market:fetch-news-positive {--save : Save CSV to storage/app/news_positive.csv}';

    protected $description = 'Use ChatGPT 5.1 API to fetch a CSV of tickers with positive sentiment from today’s news';

    public function handle()
    {
        $this->info('📡 Contacting ChatGPT 5.1…');

        $client = OpenAI::client(env('OPENAI_API_KEY'));

        // ---- You can modify this prompt however you like ---- //
        $prompt = <<<'PROMPT'
You are a dedicated financial sentiment crawler.  
Return ONLY CSV.

Scan ALL U.S. stock-related news, PR releases, SEC filings, analyst notes,
and earnings headlines from the past 48 hours.

Produce a CSV with columns:
symbol,market_cap_category,sector,reason

Rules:
- Return AT LEAST 75 tickers.
- MUST include obscure, under-the-radar, low-volume, low-float, micro-cap, and nano-cap tickers.
- Include anything with:
  * positive earnings surprise
  * analyst upgrade or raised price target
  * FDA approval or breakthrough designation
  * major contract or government deal
  * strategic partnership
  * successful clinical data
  * buyback program or dividend increase
  * strong forward guidance
  * acquisition interest or merger
- Only include stocks with positive catalysts.
- No commentary. CSV ONLY.

market_cap_category:
  large, mid, small, micro, nano/lowfloat

sector:
  broad sector classification

reason:
  4–10 word positive catalyst summary.

PROMPT;

        try {
            $response = $client->chat()->create([
                'model' => 'gpt-5.1',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a financial news scanner that outputs only CSV.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
            ]);
        } catch (\Exception $e) {
            $this->error('❌ API Error: '.$e->getMessage());

            return 1;
        }

        $csv = $response->choices[0]->message->content ?? '';

        if (empty($csv)) {
            $this->error('❌ No CSV returned.');

            return 1;
        }

        $this->info("\n--- CSV Returned ---");
        $this->line($csv);
        $this->info("--- End CSV ---\n");

        // Parse CSV into array for programmatic use:
        $rows = array_map('str_getcsv', explode("\n", trim($csv)));
        $headers = array_shift($rows);

        $tickers = [];
        foreach ($rows as $row) {
            if (count($row) < 1) {
                continue;
            }
            $tickers[] = [
                'symbol' => trim($row[0]),
                'reason' => $row[1] ?? '',
            ];
        }

        $this->info('✔ Parsed '.count($tickers).' tickers.');

        // Optional save
        if ($this->option('save')) {
            $path = storage_path('app/news_positive.csv');
            file_put_contents($path, $csv);
            $this->info("💾 Saved to: $path");
        }

        // Show parsed array nicely
        $this->info("\nParsed tickers:");
        foreach ($tickers as $t) {
            $this->line("- {$t['symbol']} ({$t['reason']})");
        }

        // You can return this array or pass into other services
        return 0;
    }
}
