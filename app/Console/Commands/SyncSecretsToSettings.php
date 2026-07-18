<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

class SyncSecretsToSettings extends Command
{
    protected $signature = 'settings:sync-secrets';

    protected $description = 'Read .secret file and update settings table with real credential values';

    public function handle(): int
    {
        $secretPath = base_path('.secret');

        if (! file_exists($secretPath)) {
            $this->error(".secret file not found at {$secretPath}");

            return self::FAILURE;
        }

        // Parse .secret file directly (same format as .env)
        $secrets = [];
        $lines = file($secretPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Handle commented-out lines — strip the leading # if the key matches
            $isCommented = $line[0] === '#';
            $cleanLine = $isCommented ? ltrim(substr($line, 1)) : $line;

            if (! str_contains($cleanLine, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $cleanLine, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip surrounding quotes if present
            if (strlen($value) >= 2) {
                if (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $secrets[$key] = $value;
        }

        // Map .secret keys to settings table names
        $mapping = [
            'ALPACA_KEY_ID' => 'trading2.credentials.PAPER_ALPACA_KEY_ID',
            'ALPACA_SECRET_KEY' => 'trading2.credentials.PAPER_ALPACA_SECRET_KEY',
            'ALPACA_API_KEY' => 'trading2.credentials.ALPACA_API_KEY',
            'ALPACA_API_SECRET' => 'trading2.credentials.ALPACA_API_SECRET',
            'ALPACA_PAPER_TRADING' => 'trading2.credentials.ALPACA_PAPER_TRADING',
            'MAIL_USERNAME' => 'trading2.credentials.MAIL_USERNAME',
            'MAIL_PASSWORD' => 'trading2.credentials.MAIL_PASSWORD',
            'OPENAI_API_KEY' => 'trading2.credentials.OPENAI_API_KEY',
            // PROD keys use the same credentials as paper trading
            'PROD_ALPACA_KEY_ID' => 'trading2.credentials.PROD_ALPACA_KEY_ID',
            'PROD_ALPACA_SECRET_KEY' => 'trading2.credentials.PROD_ALPACA_SECRET_KEY',
        ];

        $updated = 0;
        $skipped = 0;

        foreach ($mapping as $secretKey => $settingName) {
            if (! array_key_exists($secretKey, $secrets) || $secrets[$secretKey] === '') {
                $this->warn("  {$secretKey}: not set in .secret — skipping");
                $skipped++;

                continue;
            }

            $value = $secrets[$secretKey];

            Setting::updateOrCreate(
                ['name' => $settingName],
                ['value' => $value]
            );

            $masked = strlen($value) > 12
                ? substr($value, 0, 8).'...'.substr($value, -4)
                : '***';

            $this->info("  {$settingName} → {$masked}");
            $updated++;
        }

        $this->newLine();
        $this->info("Synced {$updated} values from .secret, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
