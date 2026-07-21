<?php

namespace App\Services;

use App\Services\Trading\ProfitProtectionStopCalculator;
use App\Trading\SymbolBlacklist;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AtrPerformanceService
{
    // FORCE-RECOMPILE-2026-07-01-v2
    public function analyzeVersion(string $algoVersion, ?string $pipelineRun = null, ?string $startDate = null, ?string $endDate = null, ?string $entryType = null, string $tableName = 'trade_alerts', bool $firstOnly = false, bool $hideBlacklisted = true, float $mlMinPct = 0.0, bool $useFullTables = false, bool $useTimeSlots = false): array
    {
        \Log::info('analyzeVersion called', [
            'firstOnly' => $firstOnly,
            'firstOnly_type' => gettype($firstOnly),
            'hideBlacklisted' => $hideBlacklisted,
            'hideBlacklisted_type' => gettype($hideBlacklisted),
            'pipeline' => $pipelineRun,
            'version' => $algoVersion,
            'useFullTables' => $useFullTables,
            'useTimeSlots' => $useTimeSlots,
        ]);

        // Get available entry types for the filter
        $availableTypesQuery = "SELECT DISTINCT entry_type FROM {$tableName} WHERE 1=1";
        $availableTypesParams = [];

        // Handle ALL pipelines case
        if ($pipelineRun && $pipelineRun !== 'ALL') {
            $availableTypesQuery .= ' AND version = ? AND pipeline_run = ?';
            $availableTypesParams = [$algoVersion, $pipelineRun];
        } elseif ($pipelineRun === 'ALL') {
            // For ALL, don't filter by version since each pipeline has different versions
            $availableTypesQuery .= ' AND pipeline_run IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $availableTypesParams = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'];
        } else {
            $availableTypesQuery .= ' AND version = ?';
            $availableTypesParams = [$algoVersion];
        }

        $availableTypesQuery .= ' AND entry_type IS NOT NULL ORDER BY entry_type';
        $availableTypes = DB::select($availableTypesQuery, $availableTypesParams);
        $entryTypesList = array_map(fn ($row) => $row->entry_type, $availableTypes);

        // Only include calculated_position_size if querying trade_alerts table (not unfiltered)
        $positionSizeColumn = $tableName === 'trade_alerts' ? 'ta.calculated_position_size,' : '';

        $query = "
            SELECT
                ta.symbol,
                ai.id as asset_id,
                ta.entry_type,
                ta.entry_ts_est,
                ta.signal_ts_est,
                ta.entry,
                ta.stop,
                ta.risk_pct,
                ta.score,
                ta.vol_ratio,
                ta.atr,
                ta.atr_pct,
                ta.suggested_trailing_stop,
                ta.suggested_trailing_stop_pct,
                ta.targets,
                ta.version,
                ta.pipeline_run,
                ta.ml_win_prob,
                ta.ml_scored_at,
                ta.ml_model_version,
                ta.analyzed,
                ta.exit_price,
                ta.exit_ts_est,
                ta.exit_reason,
                ta.pnl_percent,
                ta.pnl_dollar,
                ta.max_adverse_excursion,
                ta.hold_time_minutes,
                ta.r_multiple,
                ta.target_hit,
                {$positionSizeColumn}
                ta.created_at
            FROM {$tableName} ta
            LEFT JOIN asset_info ai ON ta.symbol = ai.symbol AND ta.asset_type = ai.asset_type
            WHERE 1=1";

        $params = [];

        // Handle ALL pipelines case
        if ($pipelineRun && $pipelineRun !== 'ALL') {
            $query .= ' AND ta.version = ? AND ta.pipeline_run = ?';
            $params = [$algoVersion, $pipelineRun];
        } elseif ($pipelineRun === 'ALL') {
            // For ALL, get all pipelines regardless of version
            $query .= ' AND ta.pipeline_run IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $params = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'];
        } else {
            $query .= ' AND ta.version = ?';
            $params = [$algoVersion];
        }

        if ($startDate) {
            $query .= ' AND ta.entry_ts_est >= ?';
            $params[] = $startDate.' 00:00:00';
        }
        if ($endDate) {
            $query .= ' AND ta.entry_ts_est <= ?';
            $params[] = $endDate.' 23:59:59';
        }

        if ($entryType) {
            $query .= ' AND ta.entry_type = ?';
            $params[] = $entryType;
        }

        // Skip-first-minutes filter for Pipeline R: exclude entries within X minutes of 9:30 AM EST
        $skipFirstMinutes = TradingSettingService::getRealtimeSkipFirstMinutes();
        \Log::info('SKIP_FILTER_CHECK', ['skipFirstMinutes' => $skipFirstMinutes, 'pipelineRun' => $pipelineRun, 'R_match' => ($pipelineRun === 'R'), 'ALL_match' => ($pipelineRun === 'ALL')]);
        if ($skipFirstMinutes > 0 && ($pipelineRun === 'R' || $pipelineRun === 'ALL')) {
            $timeStr = sprintf('%02d:%02d:00', 9, 30 + $skipFirstMinutes);
            // Also filter on signal_ts_est because analyzeAlert() uses signal_ts_est as the
            // displayed entry time when it differs from entry_ts_est. Without this, trades
            // whose signal fired inside the skip window (but entry was later) still appear
            // with a pre-cutoff time on the results page.
            $query .= " AND (ta.pipeline_run != 'R' OR (TIME(ta.entry_ts_est) >= ? AND (ta.signal_ts_est IS NULL OR TIME(ta.signal_ts_est) >= ?)))";
            $params[] = $timeStr;
            $params[] = $timeStr;
            \Log::info('SKIP_FILTER_ADDED', ['time' => $timeStr]);
        }

        $query .= '
              AND ta.atr IS NOT NULL
            ORDER BY ta.entry_ts_est ASC';

        $skipFilterActive = $skipFirstMinutes > 0 && ($pipelineRun === 'R' || $pipelineRun === 'ALL');
        \Log::info('SQL_FILTER: timeFilter='.($skipFilterActive ? '>=09:'.(30 + $skipFirstMinutes).':00' : 'NONE').' pipeline='.$pipelineRun);
        $alerts = DB::select($query, $params);
        \Log::info('SQL_RESULT: '.count($alerts).' alerts returned');

        $alertCountBefore = count($alerts);

        if (empty($alerts)) {
            return [
                'summary' => null,
                'trades' => [],
                'target_breakdown' => [],
                'available_entry_types' => $entryTypesList,
            ];
        }

        \Log::info('analyzeVersion called', [
            'firstOnly' => $firstOnly,
            'firstOnly_type' => gettype($firstOnly),
            'hideBlacklisted' => $hideBlacklisted,
            'hideBlacklisted_type' => gettype($hideBlacklisted),
            'pipeline' => $pipelineRun,
            'version' => $algoVersion,
            'useFullTables' => $useFullTables,
            'useTimeSlots' => $useTimeSlots,
        ]);

        if ($useTimeSlots) {
            $globalTimeSlots = TradingSettingService::getTimeSlots();
            $realtimeSlots = TradingSettingService::getRealtimeSlots();
            $alertCountBeforeTimeSlots = count($alerts);
            $alerts = array_values(array_filter($alerts, function ($alert) use ($globalTimeSlots, $realtimeSlots) {
                $slotKey = $this->getEntryTimeSlotKey($alert->entry_ts_est ?? null);
                if ($slotKey === null) {
                    return false;
                }

                // Pipeline R uses its own realtime time slots
                if (($alert->pipeline_run ?? '') === 'R') {
                    return $realtimeSlots[$slotKey] ?? false;
                }

                return $globalTimeSlots[$slotKey] ?? false;
            }));

            \Log::info('Time slot filter applied', [
                'before' => $alertCountBeforeTimeSlots,
                'after' => count($alerts),
                'removed' => $alertCountBeforeTimeSlots - count($alerts),
            ]);
        }

        // Pre-filter by ML threshold before firstOnly dedup so "first" means first qualifying alert.
        // A negative threshold value is the UI's ".env" mode, which means use each pipeline's
        // configured threshold rather than a single fixed cutoff.
        if ($mlMinPct !== 0.0) {
            $usePipelineThresholds = $mlMinPct < 0;
            $mlMinDecimal = $usePipelineThresholds ? null : $mlMinPct / 100.0;
            $alertCountBeforeMl = count($alerts);

            $alerts = array_values(array_filter($alerts, function ($alert) use ($mlMinDecimal, $usePipelineThresholds) {
                if ($alert->ml_win_prob === null) {
                    return false;
                }

                $threshold = $usePipelineThresholds
                    ? TradingSettingService::getPipelineMlThreshold((string) ($alert->pipeline_run ?? ''))
                    : $mlMinDecimal;

                return $threshold !== null && (float) $alert->ml_win_prob >= $threshold;
            }));

            \Log::info('ML threshold pre-filter applied', [
                'ml_min_pct' => $mlMinPct,
                'mode' => $usePipelineThresholds ? 'per_pipeline' : 'fixed',
                'before' => $alertCountBeforeMl,
                'after' => count($alerts),
            ]);
        }

        // Pipeline K execution filter: skip risk_pct >= 2.0% trades (matches live order placement logic).
        // These capped-risk trades have ~63% WR vs 94%+ for lower-risk Pipeline K signals.
        if ($pipelineRun === 'K') {
            $alertCountBeforeRisk = count($alerts);
            $alerts = array_values(array_filter($alerts, function ($alert) {
                return (float) ($alert->risk_pct ?? 0) < 2.0;
            }));

            \Log::info('Pipeline K risk_pct filter applied', [
                'before' => $alertCountBeforeRisk,
                'after' => count($alerts),
                'removed' => $alertCountBeforeRisk - count($alerts),
            ]);
        }

        // Filter to first alert per symbol per day if requested (matches real trading)
        if ($firstOnly) {
            $seenSymbolDates = [];
            $alerts = array_values(array_filter($alerts, function ($alert) use (&$seenSymbolDates) {
                $tradingDate = substr($alert->entry_ts_est, 0, 10);
                $key = $alert->symbol.'_'.$tradingDate;

                if (isset($seenSymbolDates[$key])) {
                    return false; // Skip duplicate
                }

                $seenSymbolDates[$key] = true;

                return true;
            }));

            \Log::info('First-only filter applied', [
                'before' => $alertCountBefore,
                'after' => count($alerts),
                'reduction' => $alertCountBefore - count($alerts),
            ]);
        }

        // Filter out blacklisted symbols if requested
        if ($hideBlacklisted) {
            $alertCountBeforeBlacklist = count($alerts);
            $alerts = array_values(array_filter($alerts, function ($alert) {
                return ! SymbolBlacklist::isBlacklisted($alert->symbol);
            }));

            \Log::info('Blacklist filter applied', [
                'before' => $alertCountBeforeBlacklist,
                'after' => count($alerts),
                'removed' => $alertCountBeforeBlacklist - count($alerts),
            ]);
        }

        $tradeResults = [];
        $winners = 0;
        $losers = 0;
        $totalPnL = 0.0;
        $totalRiskAdjustedPnL = 0.0;

        foreach ($alerts as $alert) {
            // If signal_ts_est differs from entry_ts_est, the live system bought later at a
            // different price — stored P&L is wrong, so force live recalculation from signal time.
            $signalDiffersFromEntry = isset($alert->signal_ts_est)
                && $alert->signal_ts_est !== null
                && $alert->signal_ts_est !== $alert->entry_ts_est;

            // Use pre-calculated results if available (much faster!) — but only when entry matches signal
            if (! $signalDiffersFromEntry && $alert->analyzed && $alert->exit_price !== null && $alert->pnl_percent !== null) {
                $result = $this->buildResultFromStoredData($alert);
            } else {
                // Live calculation: uses signal_ts_est price as real entry when available
                $result = $this->analyzeAlert($alert, $useFullTables);
            }

            if ($result) {
                $tradeResults[] = $result;

                if ($result['is_winner']) {
                    $winners++;
                } else {
                    $losers++;
                }

                $totalPnL += $result['pnl_percent'];
                $totalRiskAdjustedPnL += $result['risk_adjusted_return'];
            }
        }

        return $this->buildPerformanceData($tradeResults, $winners, $losers, $totalPnL, $totalRiskAdjustedPnL, $entryTypesList);
    }

    private function getEntryTimeSlotKey(?string $timestamp): ?string
    {
        if (! $timestamp) {
            return null;
        }

        try {
            $entryDate = Carbon::parse($timestamp, 'America/New_York');
        } catch (\Throwable) {
            return null;
        }

        $slotMinute = (int) floor(((int) $entryDate->format('i')) / 15) * 15;

        return $entryDate->format('H').':'.str_pad((string) $slotMinute, 2, '0', STR_PAD_LEFT);
    }

    private function buildResultFromStoredData(object $alert): array
    {
        // Use pre-calculated results from database (avoids expensive one_minute_prices queries)
        $entryPrice = (float) $alert->entry;
        $exitPrice = (float) $alert->exit_price;
        $pnlPercent = (float) $alert->pnl_percent;
        $isWinner = $pnlPercent > 0;
        $riskPct = (float) ($alert->risk_pct ?? 0);
        $rMultiple = isset($alert->r_multiple) ? (float) $alert->r_multiple : ($riskPct > 0 ? ($pnlPercent / $riskPct) : 0.0);

        // Calculate risk level (same logic as TradeAlertsController)
        $riskLevel = 'medium';
        if ($riskPct >= 3.0) {
            $riskLevel = 'high';
        } elseif ($riskPct <= 1.5) {
            $riskLevel = 'low';
        }

        // Calculate position size and dollar P&L
        $maxPositionSize = TradingSettingService::getMaxPositionSize();
        $calculatedPositionSize = isset($alert->calculated_position_size) ? (float) $alert->calculated_position_size : $maxPositionSize;
        $effectivePositionSize = min($calculatedPositionSize, $maxPositionSize);
        $pnlDollar = isset($alert->pnl_dollar) ? (float) $alert->pnl_dollar : ($exitPrice - $entryPrice);
        $numShares = $entryPrice > 0 ? ($effectivePositionSize / $entryPrice) : 0;
        $positionDollarPnl = $numShares * $pnlDollar;

        $atrTrailingStopPct = (float) ($alert->suggested_trailing_stop_pct ?? $alert->atr_pct);
        $atrTrailingStopPct = min($atrTrailingStopPct, TradingSettingService::getStopLossAtrMaxPct());

        return [
            'symbol' => (string) $alert->symbol,
            'asset_id' => $alert->asset_id ?? null,
            'entry_type' => (string) $alert->entry_type,
            'entry_price' => $entryPrice,
            'exit_price' => $exitPrice,
            'entry_time' => (string) $alert->entry_ts_est,
            'exit_time' => (string) $alert->exit_ts_est,
            'exit_reason' => (string) $alert->exit_reason,
            'pnl_percent' => $pnlPercent,
            'pnl_dollar' => $pnlDollar,
            'calculated_position_size' => $calculatedPositionSize,
            'effective_position_size' => $effectivePositionSize,
            'position_dollar_pnl' => $positionDollarPnl,
            'risk_pct' => $riskPct,
            'risk_level' => $riskLevel,
            'risk_adjusted_return' => $rMultiple,
            'r_multiple' => $rMultiple,
            'score' => (float) ($alert->score ?? 0),
            'vol_ratio' => (float) ($alert->vol_ratio ?? 0),
            'atr_pct' => (float) ($alert->atr_pct ?? 0),
            'atr_trailing_stop_pct' => $atrTrailingStopPct,
            'version' => (string) ($alert->version ?? ''),
            'pipeline_run' => (string) ($alert->pipeline_run ?? ''),
            'ml_win_prob' => isset($alert->ml_win_prob) ? (float) $alert->ml_win_prob : null,
            'ml_scored_at' => $alert->ml_scored_at ?? null,
            'ml_model_version' => $alert->ml_model_version ?? null,
            'target_hit' => (string) ($alert->target_hit ?? 'none'),
            'is_winner' => $isWinner,
            'was_stopped_out' => ($alert->exit_reason === 'atr_stop'),
        ];
    }

    /**
     * Compute the ATR-simulation P&L for a single alert, using signal_ts_est as entry
     * when it differs from entry_ts_est (same logic as analyzeVersion).
     *
     * Returns ['pnl_percent' => float, 'exit_price' => float, 'entry_price' => float]
     * or null if no price data available.
     */
    public function computePnlForAlert(object $alert, bool $useFullTables = false): ?array
    {
        $signalDiffersFromEntry = isset($alert->signal_ts_est)
            && $alert->signal_ts_est !== null
            && $alert->signal_ts_est !== $alert->entry_ts_est;

        if (! $signalDiffersFromEntry && $alert->analyzed && $alert->exit_price !== null && $alert->pnl_percent !== null) {
            return [
                'pnl_percent' => (float) $alert->pnl_percent,
                'exit_price' => (float) $alert->exit_price,
                'entry_price' => (float) $alert->entry,
            ];
        }

        $result = $this->analyzeAlert($alert, $useFullTables);

        if (! $result) {
            return null;
        }

        return [
            'pnl_percent' => $result['pnl_percent'],
            'exit_price' => $result['exit_price'],
            'entry_price' => $result['entry_price'],
        ];
    }

    private function analyzeAlert(object $alert, bool $useFullTables = false): ?array
    {
        $oneMinutePriceTable = $useFullTables ? 'one_minute_prices_full' : 'one_minute_prices';

        $symbol = (string) $alert->symbol;
        $entryTime = (string) $alert->entry_ts_est;

        // If the scanner confirmed at a later signal_ts_est, use that time and its market price
        // as the real entry — this matches when the live system actually places the order.
        $signalTsEst = isset($alert->signal_ts_est) ? (string) $alert->signal_ts_est : null;
        if ($signalTsEst && $signalTsEst !== $entryTime) {
            $signalBar = DB::selectOne("
                SELECT price as close
                                FROM {$oneMinutePriceTable}
                WHERE asset_type = ? AND symbol = ?
                  AND ts_est <= ?
                ORDER BY ts_est DESC
                LIMIT 1
                        ", ['stock', $symbol, $signalTsEst]);

            if ($signalBar) {
                $entryTime = $signalTsEst;
            }
        }

        $entryPrice = isset($signalBar) && $signalBar ? (float) $signalBar->close : (float) $alert->entry;

        // Use the suggested trailing stop from the alert (already has minimum applied)
        $atrTrailingStopPct = (float) ($alert->suggested_trailing_stop_pct ?? $alert->atr_pct);
        // Cap trailing stop at configured maximum
        $atrTrailingStopPct = min($atrTrailingStopPct, TradingSettingService::getStopLossAtrMaxPct());
        $atrPct = (float) $alert->atr_pct;

        // When using signal_ts_est price, recalculate the initial stop from the new entry price
        // using the same ATR trailing stop %. The original stop was anchored to entry_ts_est price.
        $originalStop = (float) $alert->stop;
        $initialStopPrice = (isset($signalBar) && $signalBar && $atrTrailingStopPct > 0)
            ? $entryPrice * (1 - ($atrTrailingStopPct / 100))
            : $originalStop;

        $riskPct = $entryPrice > 0 && $initialStopPrice > 0
            ? abs((($entryPrice - $initialStopPrice) / $entryPrice) * 100)
            : (float) ($alert->risk_pct ?? 0);

        $score = (float) ($alert->score ?? 0);
        $volRatio = (float) ($alert->vol_ratio ?? 0);

        $marketClose = date('Y-m-d', strtotime($entryTime)).' 15:59:00';

        $bars = DB::select("
            SELECT ts_est, open, high, low, price as close
                        FROM {$oneMinutePriceTable}
            WHERE asset_type = ? AND symbol = ?
              AND ts_est > ? AND ts_est <= ?
            ORDER BY ts_est ASC
                ", ['stock', $symbol, $entryTime, $marketClose]);

        if (empty($bars)) {
            return null;
        }

        // Start with fixed initial stop; trailing/protection logic activates on first gain.
        $highestPrice = $entryPrice;
        $currentStop = $initialStopPrice;
        $trailingActive = false;
        $activationThreshold = $entryPrice * 1.01; // legacy 1% threshold (unused when profit protection on)
        $profitProtectionEnabled = ProfitProtectionStopCalculator::isEnabled();
        $exitPrice = null;
        $exitTime = null;
        $exitReason = null;

        foreach ($bars as $bar) {
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $open = (float) $bar->open;

            // Check if stopped out - account for gaps
            if ($low <= $currentStop) {
                // If opened below stop, you're filled at open (gap down slippage)
                // Otherwise, you're filled at stop price
                $exitPrice = ($open < $currentStop) ? $open : $currentStop;
                $exitTime = (string) $bar->ts_est;
                $exitReason = 'atr_stop';
                break;
            }

            if ($high > $highestPrice) {
                $highestPrice = $high;
            }

            if ($profitProtectionEnabled) {
                // Tiered profit-protection stop: tightens at +0.75%, locks at +1.25% / +2.00%, trails above.
                $newStop = ProfitProtectionStopCalculator::calculateStop(
                    entryPrice: $entryPrice,
                    sessionHighPrice: $highestPrice,
                    atrPct: $atrPct,
                    currentStop: $currentStop,
                );

                if ($newStop > $currentStop) {
                    $currentStop = $newStop;
                }
            } else {
                // Legacy: activate trailing after 1% gain, then trail by ATR %
                if (! $trailingActive && $highestPrice >= $activationThreshold) {
                    $trailingActive = true;
                    $currentStop = $highestPrice * (1 - ($atrTrailingStopPct / 100));
                } elseif ($trailingActive) {
                    $newTrailingStop = $highestPrice * (1 - ($atrTrailingStopPct / 100));

                    if ($newTrailingStop > $currentStop) {
                        $currentStop = $newTrailingStop;
                    }
                }
            }
        }

        if ($exitPrice === null) {
            $lastBar = end($bars);
            $exitPrice = (float) $lastBar->close;
            $exitTime = (string) $lastBar->ts_est;
            $exitReason = 'market_close';
        }

        $pnlDollar = $exitPrice - $entryPrice;
        $pnlPercent = ($entryPrice > 0) ? (($pnlDollar / $entryPrice) * 100) : 0.0;

        // Filter out impossible P&L (bad data like reverse splits, data errors)
        // Real intraday moves rarely exceed -50% in one bar
        if ($pnlPercent < -50.0) {
            return null;  // Skip this trade - likely bad data
        }

        $isWinner = $pnlPercent > 0;

        $riskAdjustedReturn = $riskPct > 0 ? ($pnlPercent / $riskPct) : 0.0;

        $targets = json_decode((string) ($alert->targets ?? ''), true);
        $targetHit = 'none';
        if (is_array($targets) && $isWinner) {
            if (isset($targets['3R']) && $exitPrice >= (float) $targets['3R']) {
                $targetHit = '3R';
            } elseif (isset($targets['2R']) && $exitPrice >= (float) $targets['2R']) {
                $targetHit = '2R';
            } elseif (isset($targets['1R']) && $exitPrice >= (float) $targets['1R']) {
                $targetHit = '1R';
            }
        }

        $wasStoppedOut = ($exitReason === 'atr_stop');

        // Calculate risk level (same logic as TradeAlertsController)
        $riskLevel = 'medium';
        if ($riskPct >= 3.0) {
            $riskLevel = 'high';
        } elseif ($riskPct <= 1.5) {
            $riskLevel = 'low';
        }

        // Calculate position size and dollar P&L
        // Use max position size from config to match real trading (not the alert's calculated size which may be higher)
        $maxPositionSize = TradingSettingService::getMaxPositionSize();
        $calculatedPositionSize = isset($alert->calculated_position_size) ? (float) $alert->calculated_position_size : $maxPositionSize;
        // Cap at max to match real trading behavior
        $effectivePositionSize = min($calculatedPositionSize, $maxPositionSize);
        $numShares = $entryPrice > 0 ? ($effectivePositionSize / $entryPrice) : 0;
        $positionDollarPnl = $numShares * $pnlDollar;

        return [
            'symbol' => $symbol,
            'asset_id' => $alert->asset_id ?? null,
            'entry_type' => (string) $alert->entry_type,
            'entry_price' => $entryPrice,
            'exit_price' => $exitPrice,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'exit_reason' => $exitReason,
            'pnl_percent' => $pnlPercent,
            'pnl_dollar' => $pnlDollar,
            'calculated_position_size' => $calculatedPositionSize,
            'effective_position_size' => $effectivePositionSize,
            'position_dollar_pnl' => $positionDollarPnl,
            'risk_pct' => $riskPct,
            'risk_level' => $riskLevel,
            'risk_adjusted_return' => $riskAdjustedReturn,
            'r_multiple' => $riskAdjustedReturn, // Add r_multiple for frontend calculation
            'score' => $score,
            'vol_ratio' => $volRatio,
            'atr_pct' => $atrPct,
            'atr_trailing_stop_pct' => $atrTrailingStopPct,
            'version' => (string) ($alert->version ?? ''),
            'pipeline_run' => (string) ($alert->pipeline_run ?? ''),
            'ml_win_prob' => isset($alert->ml_win_prob) ? (float) $alert->ml_win_prob : null,
            'ml_scored_at' => $alert->ml_scored_at ?? null,
            'ml_model_version' => $alert->ml_model_version ?? null,
            'target_hit' => $targetHit,
            'is_winner' => $isWinner,
            'was_stopped_out' => $wasStoppedOut,
        ];
    }

    private function buildPerformanceData(array $tradeResults, int $winners, int $losers, float $totalPnL, float $totalRiskAdjustedPnL, array $entryTypesList): array
    {
        $totalTrades = count($tradeResults);
        $winRate = $totalTrades > 0 ? ($winners / $totalTrades) * 100 : 0.0;
        $avgPnL = $totalTrades > 0 ? $totalPnL / $totalTrades : 0.0;
        $avgRiskAdjustedReturn = $totalTrades > 0 ? $totalRiskAdjustedPnL / $totalTrades : 0.0;

        $winningTrades = array_filter($tradeResults, fn ($t) => (bool) $t['is_winner']);
        $losingTrades = array_filter($tradeResults, fn ($t) => ! (bool) $t['is_winner']);

        $avgWinningTrade = ! empty($winningTrades)
            ? array_sum(array_column($winningTrades, 'pnl_percent')) / count($winningTrades)
            : 0.0;

        $avgLosingTrade = ! empty($losingTrades)
            ? array_sum(array_column($losingTrades, 'pnl_percent')) / count($losingTrades)
            : 0.0;

        $grossProfit = ! empty($winningTrades)
            ? array_sum(array_column($winningTrades, 'pnl_percent'))
            : 0.0;

        $grossLoss = ! empty($losingTrades)
            ? abs(array_sum(array_column($losingTrades, 'pnl_percent')))
            : 0.0;

        $profitFactor = $grossLoss > 0 ? ($grossProfit / $grossLoss) : 0.0;

        $targetHits = array_count_values(array_column($tradeResults, 'target_hit'));
        $stopOuts = (int) array_sum(array_map(fn ($t) => $t['was_stopped_out'] ? 1 : 0, $tradeResults));

        $avgAtrPct = $totalTrades > 0
            ? array_sum(array_column($tradeResults, 'atr_pct')) / $totalTrades
            : 0.0;

        // Calculate dollar-based metrics
        $totalDollarPnl = array_sum(array_column($tradeResults, 'position_dollar_pnl'));
        $realizedProfitDollar = ! empty($winningTrades)
            ? array_sum(array_column($winningTrades, 'position_dollar_pnl'))
            : 0.0;
        $realizedLossDollar = ! empty($losingTrades)
            ? array_sum(array_column($losingTrades, 'position_dollar_pnl'))
            : 0.0;
        $totalAmountInvested = array_sum(array_column($tradeResults, 'effective_position_size'));

        // Sort trades by P&L for display
        usort($tradeResults, fn ($a, $b) => $b['pnl_percent'] <=> $a['pnl_percent']);

        return [
            'summary' => [
                'total_trades' => $totalTrades,
                'winners' => $winners,
                'losers' => $losers,
                'win_rate' => $winRate,
                'avg_pnl' => $avgPnL,
                'total_pnl' => $totalPnL,
                'avg_winning_trade' => $avgWinningTrade,
                'avg_losing_trade' => $avgLosingTrade,
                'gross_profit' => $grossProfit,
                'gross_loss' => $grossLoss,
                'profit_factor' => $profitFactor,
                'avg_risk_adjusted_return' => $avgRiskAdjustedReturn,
                'avg_atr_pct' => $avgAtrPct,
                'stop_outs' => $stopOuts,
                'stop_out_rate' => $totalTrades > 0 ? ($stopOuts / $totalTrades) * 100 : 0.0,
                'total_dollar_pnl' => $totalDollarPnl,
                'realized_profit_dollar' => $realizedProfitDollar,
                'realized_loss_dollar' => $realizedLossDollar,
                'total_amount_invested' => $totalAmountInvested,
            ],
            'trades' => $tradeResults,
            'target_breakdown' => [
                ['target' => '3R', 'count' => $targetHits['3R'] ?? 0],
                ['target' => '2R', 'count' => $targetHits['2R'] ?? 0],
                ['target' => '1R', 'count' => $targetHits['1R'] ?? 0],
                ['target' => 'none', 'count' => $targetHits['none'] ?? 0],
            ],
            'available_entry_types' => $entryTypesList,
        ];
    }
}
