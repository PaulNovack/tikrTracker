<?php

namespace App\Services;

use App\Models\AssetInfo;
use App\Models\SymbolFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SymbolBlacklistService
{
    /**
     * Record a symbol failure and check for auto-blacklisting
     */
    public function recordFailure(
        string $symbol,
        string $assetType,
        string $errorMessage,
        string $failureType = 'api_error'
    ): void {
        try {
            DB::transaction(function () use ($symbol, $assetType, $errorMessage, $failureType) {
                // Determine failure type based on error message
                $detectedFailureType = $this->detectFailureType($errorMessage, $failureType);

                // Find or create failure record
                $failure = SymbolFailure::where('symbol', $symbol)
                    ->where('asset_type', $assetType)
                    ->where('failure_type', $detectedFailureType)
                    ->first();

                if ($failure) {
                    // Update existing failure record
                    $failure->update([
                        'consecutive_count' => $failure->consecutive_count + 1,
                        'last_failure_at' => now(),
                        'error_message' => $errorMessage, // Keep latest error message
                    ]);
                } else {
                    // Create new failure record
                    $failure = SymbolFailure::create([
                        'symbol' => $symbol,
                        'asset_type' => $assetType,
                        'failure_type' => $detectedFailureType,
                        'error_message' => $errorMessage,
                        'consecutive_count' => 1,
                        'first_failure_at' => now(),
                        'last_failure_at' => now(),
                        'auto_blacklisted' => false,
                    ]);
                }

                // Check if symbol should be auto-blacklisted
                if (! $failure->auto_blacklisted && $failure->shouldAutoBlacklist()) {
                    $this->autoBlacklistSymbol($failure);
                }
            });
        } catch (\Exception $e) {
            Log::error("Failed to record symbol failure for {$symbol}: {$e->getMessage()}");
        }
    }

    /**
     * Record a successful collection to reset failure counters
     */
    public function recordSuccess(string $symbol, string $assetType): void
    {
        try {
            // Clear any existing failure records for this symbol
            SymbolFailure::where('symbol', $symbol)
                ->where('asset_type', $assetType)
                ->where('auto_blacklisted', false)
                ->delete();
        } catch (\Exception $e) {
            Log::error("Failed to record symbol success for {$symbol}: {$e->getMessage()}");
        }
    }

    /**
     * Detect failure type based on error message patterns
     */
    private function detectFailureType(string $errorMessage, string $defaultType): string
    {
        $errorMessage = strtolower($errorMessage);

        if (str_contains($errorMessage, 'possibly delisted') ||
            str_contains($errorMessage, 'delisted') ||
            str_contains($errorMessage, 'no price data found')) {
            return 'delisted';
        }

        if (str_contains($errorMessage, 'no data returned') ||
            str_contains($errorMessage, 'empty') ||
            str_contains($errorMessage, 'no market hours data')) {
            return 'no_data';
        }

        if (str_contains($errorMessage, 'nan') ||
            str_contains($errorMessage, 'invalid data') ||
            str_contains($errorMessage, 'data quality')) {
            return 'data_quality';
        }

        return $defaultType;
    }

    /**
     * Automatically blacklist a symbol based on failure patterns
     */
    private function autoBlacklistSymbol(SymbolFailure $failure): void
    {
        try {
            // Soft delete the symbol in asset_info
            $asset = AssetInfo::where('symbol', $failure->symbol)
                ->where('asset_type', $failure->asset_type)
                ->first();

            if ($asset && ! $asset->trashed()) {
                $asset->update([
                    'reason_for_delete' => $failure->getBlacklistReason(),
                    'deleted_at' => now(),
                ]);

                // Mark failure as auto-blacklisted
                $failure->update([
                    'auto_blacklisted' => true,
                    'blacklisted_at' => now(),
                ]);

                Log::info("Auto-blacklisted symbol {$failure->symbol} ({$failure->asset_type}): {$failure->getBlacklistReason()}", [
                    'symbol' => $failure->symbol,
                    'asset_type' => $failure->asset_type,
                    'failure_type' => $failure->failure_type,
                    'consecutive_count' => $failure->consecutive_count,
                    'reason' => $failure->getBlacklistReason(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to auto-blacklist symbol {$failure->symbol}: {$e->getMessage()}");
        }
    }

    /**
     * Get symbols that are candidates for manual review
     */
    public function getBlacklistCandidates(): array
    {
        return SymbolFailure::where('auto_blacklisted', false)
            ->where('consecutive_count', '>=', 5)
            ->where('first_failure_at', '>=', now()->subDays(14))
            ->with('assetInfo')
            ->orderBy('consecutive_count', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get recently auto-blacklisted symbols for monitoring
     */
    public function getRecentlyBlacklisted(int $days = 7): array
    {
        return SymbolFailure::where('auto_blacklisted', true)
            ->where('blacklisted_at', '>=', now()->subDays($days))
            ->with('assetInfo')
            ->orderBy('blacklisted_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Restore a blacklisted symbol (manual intervention)
     */
    public function restoreSymbol(string $symbol, string $assetType, string $reason = 'Manual restoration'): bool
    {
        try {
            DB::transaction(function () use ($symbol, $assetType, $reason) {
                // Restore in asset_info
                $asset = AssetInfo::withTrashed()
                    ->where('symbol', $symbol)
                    ->where('asset_type', $assetType)
                    ->first();

                if ($asset && $asset->trashed()) {
                    $asset->restore();
                    $asset->update(['reason_for_delete' => null]);
                }

                // Clear failure records
                SymbolFailure::where('symbol', $symbol)
                    ->where('asset_type', $assetType)
                    ->delete();

                Log::info("Manually restored symbol {$symbol} ({$assetType}): {$reason}");
            });

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to restore symbol {$symbol}: {$e->getMessage()}");

            return false;
        }
    }
}
