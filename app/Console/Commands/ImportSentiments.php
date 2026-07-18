<?php

namespace App\Console\Commands;

use App\Models\Sentiment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use OpenAI;

class ImportSentiments extends Command
{
    protected $signature = 'sentiments:import {--date= : Date for sentiments (YYYY-MM-DD format, defaults to today)} {--save : Save CSV to storage/app/sentiments.csv}';

    protected $description = 'Use ChatGPT to fetch sentiment analysis for major stocks and store in database';

    public function handle()
    {
        // Use Eastern Time for business logic since stock market operates in ET
        $easternTime = now()->setTimezone('America/New_York');
        $date = $this->option('date') ? Carbon::parse($this->option('date'))->setTimezone('America/New_York') : $easternTime;

        $this->info("📡 Fetching sentiment data for {$date->toDateString()} (Eastern Time)…");

        $client = OpenAI::client(env('OPENAI_API_KEY'));

        $prompt = <<<'PROMPT'
You are a financial sentiment analyst. 

Analyze current market sentiment for the top 50 most traded U.S. stocks based on recent news, analyst reports, earnings, and market activity.

Return ONLY CSV format with columns:
symbol,sentiment_text

Rules:
- Include exactly 50 major stocks like AAPL, MSFT, NVDA, GOOGL, AMZN, META, TSLA, etc.
- sentiment_text should be a brief 10-20 word explanation of current positive sentiment
- Focus on stocks with POSITIVE sentiment only
- Examples:
  * "Strong Q4 earnings beat drives analyst upgrades and price target increases"
  * "AI chip demand surge boosts revenue forecasts and margin expansion"
  * "Cloud growth acceleration and enterprise adoption exceed expectations"
- No headers in output
- CSV format only, no other text
- Return exactly 50 rows

PROMPT;

        try {
            $response = $client->chat()->create([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a financial sentiment analyst that outputs only CSV.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 2000,
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

        // Optional save raw CSV
        if ($this->option('save')) {
            $path = storage_path('app/sentiments.csv');
            file_put_contents($path, $csv);
            $this->info("💾 Raw CSV saved to: $path");
        }

        // Parse and store in database
        $imported = $this->parseAndStoreSentiments($csv, $date);

        $this->info("✅ Successfully imported {$imported} sentiment records for {$date->toDateString()}");

        return 0;
    }

    private function parseAndStoreSentiments(string $csv, Carbon $date): int
    {
        // Split lines and process each one
        $lines = explode("\n", trim($csv));

        $imported = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Use proper CSV parsing for quoted fields
            $parts = str_getcsv($line);

            if (count($parts) < 2) {
                continue;
            }

            $symbol = strtoupper(trim($parts[0]));
            $sentimentText = trim($parts[1]);

            if (empty($symbol) || empty($sentimentText)) {
                continue;
            }

            // Skip if this sentiment already exists for this date
            $existing = Sentiment::where('symbol', $symbol)
                ->where('sentiment_date', $date->toDateString())
                ->first();

            if ($existing) {
                $this->line("⚠️  Skipping {$symbol} - already exists for {$date->toDateString()}");

                continue;
            }

            try {
                Sentiment::create([
                    'symbol' => $symbol,
                    'sentiment_text' => $sentimentText,
                    'sentiment_type' => 'positive', // Since we're only fetching positive
                    'sentiment_date' => $date->toDateString(),
                    'confidence_score' => 0.85, // Default confidence for AI-generated
                ]);

                $imported++;
                $this->line("✓ Imported: {$symbol}");

            } catch (\Exception $e) {
                $this->error("❌ Failed to import {$symbol}: {$e->getMessage()}");
            }
        }

        return $imported;
    }
}
