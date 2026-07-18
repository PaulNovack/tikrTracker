<?php

namespace App\Services\Market;

use App\Models\AssetInfo;
use App\Models\Notification as AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class MarketDataFetcher
{
    private int $daysBack = 90;

    private int $hoursBack = 48;

    private int $minutesBack = 120;

    /**
     * Fetch market data for a single symbol.
     * Fetches daily, hourly, and 5-minute prices.
     */
    public function fetchForSymbol(string $symbol, string $assetType): bool
    {
        try {
            // Validate asset type
            if (! in_array($assetType, ['stock', 'crypto'])) {
                Log::error("Invalid asset type: {$assetType}");

                return false;
            }

            Log::info("Fetching market data for {$symbol} ({$assetType})");

            // Get the asset to check data afterward
            $asset = AssetInfo::where('symbol', $symbol)
                ->where('asset_type', $assetType)
                ->first();

            // Count initial prices
            $initialDailyCount = $asset?->dailyPrices()->count() ?? 0;

            // Fetch daily prices
            $this->fetchDaily($symbol, $assetType);

            // Fetch hourly prices
            $this->fetchHourly($symbol, $assetType);

            // Fetch 5-minute prices
            $this->fetch5Minute($symbol, $assetType);

            // Check if any data was actually fetched
            if ($asset) {
                $asset->refresh();
                $finalDailyCount = $asset->dailyPrices()->count();

                if ($finalDailyCount === $initialDailyCount && $initialDailyCount === 0) {
                    // No data was fetched - symbol likely doesn't exist or isn't available
                    $this->notifySymbolNotFound($symbol, $assetType);

                    return false;
                }
            }

            Log::info("Successfully fetched market data for {$symbol}");

            return true;
        } catch (Exception $e) {
            Log::error("Error fetching market data for {$symbol}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Notify admins when a symbol cannot be found or has no data available.
     */
    private function notifySymbolNotFound(string $symbol, string $assetType): void
    {
        // Get all admins to notify
        $admins = \App\Models\User::where('role', \App\UserRole::Admin)->get();

        if ($admins->isEmpty()) {
            Log::warning('No admins found to notify about missing symbol data');

            return;
        }

        // Determine helpful message based on symbol format
        $suggestion = '';
        if ($assetType === 'stock' && ! str_contains($symbol, '.')) {
            // US stock without exchange suffix - suggest international versions
            $suggestion = "This might be available on a different exchange. Try adding the exchange suffix:\n"
                ."• .TO for Toronto Stock Exchange (e.g., {$symbol}.TO for Canadian stocks)\n"
                ."• .L for London Stock Exchange (e.g., {$symbol}.L)\n"
                ."• .AS for Euronext Amsterdam\n"
                .'• Search on Yahoo Finance for the correct ticker.';
        }

        // Create notifications for all admin users using custom notification system
        $admins = User::where('role', \App\UserRole::Admin)->get();

        // Look up asset_id for the symbol if it exists
        $asset = AssetInfo::where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->first();
        $assetId = $asset ? $asset->id : null;

        foreach ($admins as $admin) {
            // Check if similar notification was already sent recently (last 24 hours)
            $recentNotification = AppNotification::where('user_id', $admin->id)
                ->where('title', "Symbol '{$symbol}' has no data available")
                ->where('created_at', '>=', now()->subDay())
                ->first();

            if (! $recentNotification) {
                AppNotification::create([
                    'user_id' => $admin->id,
                    'asset_id' => $assetId,
                    'title' => "Symbol '{$symbol}' has no data available",
                    'description' => "The {$assetType} symbol '{$symbol}' could not be found or has no price data available from Yahoo Finance. {$suggestion}",
                    'type' => 'warning',
                    'read' => false,
                ]);
            }
        }

        Log::warning("Symbol '{$symbol}' ({$assetType}) has no data available. Admins notified.");
    }

    /**
     * Fetch daily prices for a single symbol via Python script.
     */
    private function fetchDaily(string $symbol, string $assetType): void
    {
        if ($assetType === 'stock') {
            $this->callPythonScript('yfinance_stocks_daily.py', (string) $this->daysBack, $symbol);
        } else {
            $this->callPythonScript('yfinance_crypto_daily.py', (string) $this->daysBack, $symbol);
        }
    }

    /**
     * Fetch hourly prices for a single symbol via Python script.
     */
    private function fetchHourly(string $symbol, string $assetType): void
    {
        if ($assetType === 'stock') {
            $this->callPythonScript('yfinance_stocks_hourly.py', (string) $this->hoursBack, $symbol);
        } else {
            $this->callPythonScript('yfinance_crypto_hourly.py', (string) $this->hoursBack, $symbol);
        }
    }

    /**
     * Fetch 5-minute prices for a single symbol via Python script.
     */
    private function fetch5Minute(string $symbol, string $assetType): void
    {
        if ($assetType === 'stock') {
            $this->callPythonScript('yfinance_stocks_5min.py', (string) $this->minutesBack, $symbol);
        } else {
            $this->callPythonScript('yfinance_crypto_5min.py', (string) $this->minutesBack, $symbol);
        }
    }

    /**
     * Call a Python script with symbol filter.
     * Scripts handle filtering by environment variable.
     */
    private function callPythonScript(string $scriptName, string $timeParam, string $symbol): void
    {
        $pythonDir = base_path('python');
        $venvPath = "{$pythonDir}/venv/bin/python";
        $scriptPath = "{$pythonDir}/{$scriptName}";

        if (! file_exists($venvPath)) {
            Log::warning("Python venv not found at {$venvPath}");

            return;
        }

        if (! file_exists($scriptPath)) {
            Log::warning("Python script not found at {$scriptPath}");

            return;
        }

        try {
            $process = new Process(
                [$venvPath, $scriptPath, $timeParam],
                $pythonDir,
                ['SYMBOLS' => $symbol], // Pass single symbol via env var
                null,
                3600 // 1 hour timeout
            );

            Log::debug("Running: {$scriptName} with SYMBOLS={$symbol}");

            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning("Python script failed: {$process->getErrorOutput()}");
            }
        } catch (ProcessFailedException $e) {
            Log::error("Process failed for {$scriptName}: {$e->getMessage()}");
        }
    }

    /**
     * Set days back for daily data fetching.
     */
    public function setDaysBack(int $days): self
    {
        $this->daysBack = max(1, $days);

        return $this;
    }

    /**
     * Set hours back for hourly data fetching.
     */
    public function setHoursBack(int $hours): self
    {
        $this->hoursBack = max(1, $hours);

        return $this;
    }

    /**
     * Set minutes back for 5-minute data fetching.
     */
    public function setMinutesBack(int $minutes): self
    {
        $this->minutesBack = max(1, $minutes);

        return $this;
    }
}
