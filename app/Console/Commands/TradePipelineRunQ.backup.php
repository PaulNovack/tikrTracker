    }

    private function runBacktest($scanner, $finder, TradeAlertWriterV1 $writer, string $assetType, string $version, bool $enforceCurrentFreshness = false): int
    {
        $writer->setBacktestMode(true);
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        if (! $from || ! $to) {
            $this->error('--from and --to are required in --backtest mode (YYYY-mm-dd)');

            return 1;
        }

        $step = max(1, (int) $this->option('step'));
        $timeFrom = (string) $this->option('timeFrom');
        $timeTo = (string) $this->option('timeTo');

        $startDate = strtotime($from.' 00:00:00');
        $endDate = strtotime($to.' 00:00:00');

        $totalSignals = 0;
        $totalEntries = 0;
        $totalAlerts = 0;
        $totalStaleDetected = 0;
        $totalLagDetected = 0;
        $totalLagSkipped = 0;

        $staleMinutes = (int) $this->option('stale');
        if ($staleMinutes <= 0) {
            $staleMinutes = TradingSettingService::getPipelineMaxAgeMinutes('q');
        }

        $earlyLeadMinutes = (int) config('trading.auto_alpaca_orders.stale_slot_early_lead_minutes', 11);
        $earlyLagThresholdMinutes = max(1, $staleMinutes - $earlyLeadMinutes);

        $ignoreTypes = config('trading.pipelines.q.ignore_types', []);
        $noFilterFinder = config('trading.pipelines.q.no_filter_finder', false);

        for ($d = $startDate; $d <= $endDate; $d += 86400) {
            $day = date('Y-m-d', $d);

            $tStart = strtotime($day.' '.$timeFrom);
            $tEnd = strtotime($day.' '.$timeTo);

            for ($t = $tStart; $t <= $tEnd; $t += ($step * 60)) {
                $asOfTsEst = date('Y-m-d H:i:s', $t);

                if ($enforceCurrentFreshness) {
                    $nowEpoch = strtotime(now('America/New_York')->format('Y-m-d H:i:s'));
                    $asOfEpoch = strtotime($asOfTsEst);
                    $slotLagMinutes = ($nowEpoch - $asOfEpoch) / 60;

                    if ($slotLagMinutes > $earlyLagThresholdMinutes) {
                        $totalLagDetected++;
                    }

                    if ($slotLagMinutes > $staleMinutes) {
                        $totalLagSkipped++;

                        continue;
                    }
                }

                $signals = $scanner->scan(
                    $assetType,
                    $asOfTsEst,
                    (int) $this->option('lookback'),
                    (float) $this->option('minMove'),
                    (float) $this->option('volMult'),
                    (int) $this->option('top')
                );

                $totalSignals += count($signals);

                foreach ($signals as $sig) {
                    $res = $finder->findBestLong(
                        $sig['symbol'],
                        $sig['asset_type'],
                        $sig['signal_ts_est'],
                        $asOfTsEst,
                        (int) $this->option('before'),
                        (int) $this->option('after'),
                        (int) $this->option('volLookback'),
                        (int) $this->option('pivotLookback'),
                        (string) $this->option('fill'),
                        (int) $this->option('stale'),
                        $enforceCurrentFreshness ? now('America/New_York')->format('Y-m-d H:i:s') : null
                    );

                    if (empty($res['ok']) || empty($res['best_entry'])) {
                        if ($noFilterFinder) {
                            $currentPrice = $sig['meta']['current_price'] ?? $sig['price'] ?? null;
                            $stopPrice = $currentPrice ? round($currentPrice * 0.92, 2) : null;
                            $riskPerShare = $currentPrice && $stopPrice ? round($currentPrice - $stopPrice, 4) : null;
                            $riskPct = $currentPrice && $riskPerShare ? round(($riskPerShare / $currentPrice) * 100, 2) : null;

                            $entryType = 'FILTERED_OUT';
                            if (! empty($res['filtered_best']['type'])) {
                                $entryType = 'FILTERED_'.$res['filtered_best']['type'];
                            }

                            $atr = $sig['atr'] ?? null;
                            $atrPct = $sig['atr_pct'] ?? null;
                            $suggestedTrailingStop = ($atr && $currentPrice) ? round((float) $atr * 2.5, 6) : null;
                            $suggestedTrailingStopPct = ($suggestedTrailingStop && $currentPrice) ? round(($suggestedTrailingStop / (float) $currentPrice) * 100, 6) : null;

                            $entry = [
                                'type' => $entryType,
                                'entry_ts_est' => $sig['signal_ts_est'],
                                'entry' => $currentPrice,
                                'stop' => $stopPrice,
                                'risk_pct' => $riskPct,
                                'risk_per_share' => $riskPerShare,
                                'score' => $sig['score'] ?? null,
                                'vol_ratio' => $sig['vol_ratio'] ?? null,
                                'atr' => $atr,
                                'atr_pct' => $atrPct,
                                'suggested_trailing_stop' => $suggestedTrailingStop,
                                'suggested_trailing_stop_pct' => $suggestedTrailingStopPct,
                            ];
                        } else {
                            continue;
                        }
                    } else {
                        $entry = $res['best_entry'];
                    }

                    if (! empty($ignoreTypes) && in_array($entry['type'] ?? '', $ignoreTypes, true)) {
                        continue;
                    }

                    if ($enforceCurrentFreshness && $staleMinutes > 0) {
                        $nowEpoch = strtotime(now('America/New_York')->format('Y-m-d H:i:s'));
                        $signalEpoch = strtotime($sig['signal_ts_est']);
                        $entryEpoch = strtotime($entry['entry_ts_est']);

                        $signalAgeMinutes = ($nowEpoch - $signalEpoch) / 60;
                        $entryAgeMinutes = ($nowEpoch - $entryEpoch) / 60;

                        if ($entryAgeMinutes > $staleMinutes) {
                            $totalStaleDetected++;

                            \Log::channel('stale-alerts')->warning('[Pipeline Q] Early stale alert candidate detected before write', [
                                'symbol' => $sig['symbol'] ?? null,
                                'signal_ts_est' => $sig['signal_ts_est'] ?? null,
                                'entry_ts_est' => $entry['entry_ts_est'] ?? null,
                                'as_of_ts_est' => $asOfTsEst,
                                'signal_age_minutes' => round($signalAgeMinutes, 2),
                                'entry_age_minutes' => round($entryAgeMinutes, 2),
                                'stale_limit_minutes' => $staleMinutes,
                                'mode' => 'rolling-window-backtest',
                            ]);

                            continue;
                        }
                    }

                    $totalEntries++;

                    if ($writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'Q', false)) {
                        $totalAlerts++;
                    }
                }
            }

            $this->line("Pipeline Q ({$version}): Backtest day {$day} done.");
        }

        $this->info("Pipeline Q ({$version}): Backtest complete.");
        $this->info("Total signals: {$totalSignals}");
        $this->info("Total actionable entries: {$totalEntries}");
        $this->info("Total alerts inserted (deduped): {$totalAlerts}");
        if ($enforceCurrentFreshness) {
            $this->info("Total stale candidates detected early: {$totalStaleDetected}");
            $this->info("Total lagging slots detected early: {$totalLagDetected}");
            $this->info("Total lagging slots skipped before symbol processing: {$totalLagSkipped}");
        }

        return 0;
    }

    private function runRollingWindowBacktest($scanner, $finder, TradeAlertWriterV1 $writer, string $assetType, string $version): int
    {
        $writer->setBacktestMode(true);
        $est = now('America/New_York');
        $fromDate = $est->copy()->subMinutes(5)->format('Y-m-d');
        $toDate = $est->copy()->addMinutes(6)->format('Y-m-d');
        $timeFrom = $est->copy()->subMinutes(5)->format('H:i:00');
        $timeTo = $est->copy()->addMinutes(6)->format('H:i:00');

        $this->input->setOption('from', $fromDate);
        $this->input->setOption('to', $toDate);
        $this->input->setOption('timeFrom', $timeFrom);
        $this->input->setOption('timeTo', $timeTo);

        $this->info("Pipeline Q ({$version}): Rolling window backtest");
        $this->info("From: {$fromDate} {$timeFrom} | To: {$toDate} {$timeTo}");

        return $this->runBacktest($scanner, $finder, $writer, $assetType, $version, true);
    }
}
