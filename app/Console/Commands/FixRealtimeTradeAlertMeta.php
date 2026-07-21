<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixRealtimeTradeAlertMeta extends Command
{
    protected $signature = 'app:fix-realtime-trade-alert-meta
                            {--dry-run : Show what would be fixed without making changes}';

    protected $description = 'Fix double-encoded meta JSON in realtime trade_alert records';

    public function handle(): int
    {
        $query = \App\Models\TradeAlert::query()
            ->where('signal_type', 'REALTIME_MOMENTUM')
            ->whereNotNull('meta')
            ->whereRaw('meta LIKE \'"\\\\"%\'');

        $total = $query->count();

        $this->info("Found {$total} realtime trade_alerts with double-encoded meta.");

        if ($total === 0) {
            $this->info('Nothing to fix.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $samples = $query->limit(3)->get();
            foreach ($samples as $alert) {
                $this->line("  #{$alert->id} {$alert->symbol} — meta is string, not array");
            }
            $this->info('Dry run — no changes made.');

            return self::SUCCESS;
        }

        $fixed = 0;
        $query->chunk(200, function ($alerts) use (&$fixed): void {
            foreach ($alerts as $alert) {
                $raw = $alert->getRawOriginal('meta');
                if (! is_string($raw)) {
                    continue;
                }

                // Strip any wrapping double-quotes (double/triple encoding)
                $stripped = $raw;
                while (is_string($stripped)) {
                    $decoded = json_decode($stripped, true);
                    if (is_array($decoded)) {
                        break;
                    }
                    // Try stripping outer quotes
                    $trimmed = trim($stripped, '"');
                    if ($trimmed === $stripped) {
                        break; // no change, give up
                    }
                    $stripped = $trimmed;
                }

                if (! isset($decoded) || ! is_array($decoded)) {
                    $this->warn("  #{$alert->id} {$alert->symbol} — could not decode meta, skipping");

                    continue;
                }

                $alert->updateQuietly(['meta' => $decoded]);
                $fixed++;
            }
        });

        $this->info("Fixed {$fixed} records.");

        return self::SUCCESS;
    }
}
