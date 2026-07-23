<?php

namespace App\Console\Commands;

use App\Models\AlertLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ScanThreeWhiteSoldiersLive extends Command
{
    protected $signature = 'scan:three-white-soldiers-live
                            {--user-id= : User ID for alert records (default: first admin user)}';

    protected $description = 'Check for CDL3WHITESOLDIERS patterns via Flask API and create alert_log + notification records';

    private string $flaskBaseUrl = 'http://127.0.0.1:5000';

    public function handle(): int
    {
        if (! TradingSettingService::isThreeWhiteSoldiersScanEnabled()) {
            $this->info('Three White Soldiers scanner is disabled. Enable it in /trading-settings-2 → Other tab.');

            return self::SUCCESS;
        }

        $userId = $this->resolveUserId();

        if ($userId === null) {
            $this->error('No valid user_id found. Pass --user-id or ensure an admin user exists.');

            return self::FAILURE;
        }

        $response = Http::timeout(120)->get("{$this->flaskBaseUrl}/api/scan-intraday", [
            'pattern' => 'CDL3WHITESOLDIERS',
            'limit' => 750,
        ]);

        if (! $response->successful()) {
            $this->warn('Flask API returned non-success: '.$response->status());

            return self::SUCCESS;
        }

        $data = $response->json();
        $results = $data['results'] ?? [];

        $bullish = array_filter($results, fn ($r) => ($r['signal'] ?? '') === 'bullish');

        if (empty($bullish)) {
            $this->info('No CDL3WHITESOLDIERS patterns detected.');

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($bullish as $hit) {
            $symbol = $hit['symbol'];

            $alreadyLogged = AlertLog::query()
                ->where('symbol', $symbol)
                ->where('direction', 'up')
                ->where('created_at', '>=', now()->subMinutes(10))
                ->whereNull('price_alert_id')
                ->exists();

            if ($alreadyLogged) {
                continue;
            }

            $closePrice = $this->extractClosePrice($hit);

            AlertLog::create([
                'user_id' => $userId,
                'price_alert_id' => null,
                'symbol' => $symbol,
                'direction' => 'up',
                'trigger_price' => $closePrice ?? 0,
                'current_price' => $closePrice ?? 0,
                'trigger_percentage' => 0,
                'email_status' => 'sent',
                'sent_at' => now(),
            ]);

            $newsLink = TradingSettingService::get('trading.news_link', 'https://finance.yahoo.com/quote/<SYMBOL>/latest-news/');
            $newsUrl = str_replace('<SYMBOL>', $symbol, $newsLink);

            $this->createNotification(
                $userId,
                "Three White Soldiers: {$symbol}",
                "📈 {$symbol} triggered a Three Advancing White Soldiers candlestick pattern".($closePrice ? ' near $'.number_format($closePrice, 2) : '').'. <a href="'.$newsUrl.'" target="_blank" rel="noopener noreferrer" style="color:#2563eb;text-decoration:underline;">Check news on '.$symbol.' here</a>',
                'success',
                $hit['asset_id'] ?? null,
            );

            $count++;

            $this->line("  <info>✓</info> AlertLog + Notification created for <comment>{$symbol}</comment>");
        }

        $this->info("Created {$count} alert_log + notification record(s) for CDL3WHITESOLDIERS patterns.");

        return self::SUCCESS;
    }

    private function resolveUserId(): ?int
    {
        $optionId = $this->option('user-id');

        if ($optionId !== null) {
            return (int) $optionId;
        }

        return User::where('role', 'admin')->value('id');
    }

    private function extractClosePrice(array $hit): ?float
    {
        $ohlc = $hit['ohlc'] ?? [];

        if (empty($ohlc)) {
            return null;
        }

        $lastBar = end($ohlc);

        return isset($lastBar['close']) ? (float) $lastBar['close'] : null;
    }

    private function createNotification(int $userId, string $title, string $description, string $type, ?int $assetId = null): void
    {
        $recentNotification = Notification::query()
            ->where('user_id', $userId)
            ->where('title', $title)
            ->where('created_at', '>=', now()->subHour())
            ->first();

        if ($recentNotification) {
            return;
        }

        Notification::create([
            'user_id' => $userId,
            'asset_id' => $assetId,
            'title' => $title,
            'description' => $description,
            'type' => $type,
            'read' => false,
        ]);

        Cache::forget(sprintf('notification-counts:%d', $userId));
    }
}
