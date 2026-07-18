<?php

namespace App\Services\Trading\Realtime;

use App\Models\TradeAlert;
use App\Services\TradingSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class FreshTradeGateService
{
    public function __construct(
        private readonly RealtimeMarketDataService $marketData,
    ) {}

    /**
     * Call this inside your existing Alpaca order listener before submitting an order.
     */
    public function passes(TradeAlert $alert): bool
    {
        if (! $alert->entry_ts_est) {
            $this->reject($alert, 'missing_entry_ts');

            return false;
        }

        $entryTs = $alert->entry_ts_est instanceof \Carbon\CarbonInterface
            ? $alert->entry_ts_est
            : CarbonImmutable::parse((string) $alert->entry_ts_est, 'America/New_York');

        $entryAgeSeconds = $entryTs->diffInSeconds(now('America/New_York'), false);

        if ($entryAgeSeconds > (int) config('trading_realtime.max_entry_age_seconds', 60)) {
            $this->reject($alert, 'entry_too_old', [
                'entry_age_seconds' => $entryAgeSeconds,
            ]);

            return false;
        }

        $quote = $this->marketData->latestQuote($alert->symbol);

        if (! $quote) {
            $this->reject($alert, 'missing_quote');

            return false;
        }

        $quoteAge = $this->marketData->quoteAgeSeconds($quote);

        if ($quoteAge === null || $quoteAge > (int) TradingSettingService::getMaxQuoteAgeSeconds()) {
            $this->reject($alert, 'quote_too_old', [
                'quote_age_seconds' => $quoteAge,
            ]);

            return false;
        }

        $bid = (float) $quote['bid'];
        $ask = (float) $quote['ask'];

        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            $this->reject($alert, 'bad_quote');

            return false;
        }

        $mid = ($bid + $ask) / 2;
        $spreadPct = (($ask - $bid) / $mid) * 100;

        if ($spreadPct > (float) TradingSettingService::getMaxSpreadPct()) {
            $this->reject($alert, 'spread_too_wide', [
                'spread_pct' => $spreadPct,
            ]);

            return false;
        }

        $entryPrice = (float) ($alert->entry_price ?? $alert->price ?? 0);

        if ($entryPrice <= 0) {
            $this->reject($alert, 'missing_entry_price');

            return false;
        }

        $moveSinceEntryPct = (($ask - $entryPrice) / $entryPrice) * 100;

        if ($moveSinceEntryPct > (float) config('trading_realtime.max_move_since_entry_pct', 0.35)) {
            $this->reject($alert, 'moved_too_far_since_entry', [
                'entry_price' => $entryPrice,
                'current_ask' => $ask,
                'move_since_entry_pct' => $moveSinceEntryPct,
            ]);

            return false;
        }

        $alert->forceFill([
            'alert_age_seconds' => $entryAgeSeconds,
            'quote_age_seconds' => $quoteAge,
            'current_bid' => $bid,
            'current_ask' => $ask,
            'current_bid_qty' => $quote['bid_qty'] ?? null,
            'current_ask_qty' => $quote['ask_qty'] ?? null,
            'current_spread_pct' => $spreadPct,
            'move_since_entry_pct' => $moveSinceEntryPct,
        ])->save();

        return true;
    }

    private function reject(TradeAlert $alert, string $reason, array $context = []): void
    {
        Log::info('Fresh trade gate rejected alert', array_merge([
            'alert_id' => $alert->id,
            'symbol' => $alert->symbol,
            'reason' => $reason,
        ], $context));

        $meta = $this->decodeMeta($alert->meta ?? null);
        $meta['fresh_gate_rejected'] = true;
        $meta['fresh_gate_rejection_reason'] = $reason;
        $meta['fresh_gate_context'] = $context;

        $alert->forceFill([
            'meta' => json_encode($meta),
        ])->save();
    }

    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
