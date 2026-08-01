<?php

namespace App\Console\Commands;

use App\Services\TradingV2\EntryTypeClassifier;
use App\Services\TradingV2\GateEvaluator;
use App\Services\TradingV2\Repositories\AlertVersionRepository;
use App\Services\TradingV2\Repositories\MySqlBarSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TradingV2Backtest extends Command
{
    protected $signature = 'trading:v2-backtest
        {--symbol= : Single symbol (omit for intraday_universe)}
        {--from= : Start date/time EST (default: today 09:30)}
        {--to= : End date/time EST (default: today 16:00)}
        {--step=5 : Step interval in minutes}
        {--pipeline= : Filter to one pipeline letter (e.g. H)}
        {--fulltable : Use five_minute_prices_full / one_minute_prices_full}
        {--write : Write alerts to trade_alerts table (is_realtime=0)}
        {--use-entry-finder : Use legacy OneMinuteEntryFinder for entry classification instead of EntryTypeClassifier}';

    protected $description = 'Run a historical backtest using TradingV2 GateEvaluator against MySQL bar data.';

    public function handle(AlertVersionRepository $versionRepo): int
    {
        // Clear any cached bars from a previous run so we always read fresh data.
        MySqlBarSource::clearCache();

        $mysqlSource = new MySqlBarSource((bool) $this->option('fulltable'));
        $evaluator = new GateEvaluator($mysqlSource);
        $classifier = new EntryTypeClassifier;
        $write = (bool) $this->option('write');
        $useEntryFinder = (bool) $this->option('use-entry-finder');

        $today = date('Y-m-d');
        $from = $this->option('from') ?: "{$today} 09:30:00";
        $to = $this->option('to') ?: "{$today} 16:00:00";
        $step = (int) $this->option('step');

        // Use the cached version config when available (invalidated on every
        // gate change via GenericTaGateVersionsController), falling back to a
        // direct DB read if Redis is unavailable. This avoids a redundant
        // MySQL query on each backtest run.
        $versions = $versionRepo->getActive();
        if ($p = $this->option('pipeline')) {
            $versions = array_values(array_filter($versions, fn ($v) => $v['pipeline_letter'] === strtoupper($p)));
        }
        if (empty($versions)) {
            $this->error('No active alert versions found.');

            return self::FAILURE;
        }

        $this->info('Versions: '.count($versions).' | Pipeline filter: '.($p ?? 'ALL'));
        $this->info("Window: {$from} → {$to} | Step: {$step}min");
        $this->line('');

        $sym = $this->option('symbol');
        $symbols = $sym ? [$sym] : DB::table('intraday_universe')->pluck('symbol')->all();
        if (empty($symbols)) {
            $this->error('No symbols to backtest.');

            return self::FAILURE;
        }
        $this->info('Symbols: '.count($symbols));

        $stats = [];
        foreach ($versions as $v) {
            $stats[$v['pipeline_letter']] = ['signals' => 0, 'entries' => 0, 'symbols' => []];
        }

        $ts = strtotime($from.' America/New_York');
        $end = strtotime($to.' America/New_York');
        $ny = new \DateTimeZone('America/New_York');
        $steps = [];
        for (; $ts <= $end; $ts += $step * 60) {
            $dt = new \DateTime('@'.$ts);
            $dt->setTimezone($ny);
            $steps[] = $dt->format('Y-m-d H:i:s');
        }

        // Debug: dump first 3 symbols first bar to verify data access
        $ny = new \DateTimeZone('America/New_York');
        $testSym = $symbols[0];
        $table = (bool) $this->option('fulltable') ? 'five_minute_prices_full' : 'five_minute_prices';
        // Find first symbol that actually has bars
        foreach ($symbols as $s) {
            $rawCount = DB::table($table)->where('symbol', $s)->where('trading_date_est', '2026-07-21')->count();
            if ($rawCount > 0) {
                $testSym = $s;
                $this->info("Using {$testSym} as test symbol ({$rawCount} rows)");
                break;
            }
        }
        $testDt = new \DateTime($steps[0], $ny);
        $testTs = $testDt->format('Y-m-d H:i:00');
        $this->info("Using {$testSym} as test symbol");

        // DIRECT test of MySqlBarSource bypassing evaluator
        $direct = (new \App\Services\TradingV2\Repositories\MySqlBarSource((bool) $this->option('fulltable')))
            ->getBarsRange('5m', $testSym, '2026-07-24 08:00:00', '2026-07-24 09:30:00', 200);
        $this->info('  Direct getBarsRange count: '.count($direct));
        if (count($direct) > 0) {
            $first = $direct[0];
            $last = $direct[count($direct) - 1];
            $this->info("  First: ts={$first->tsEst} close={$first->close} | Last: ts={$last->tsEst} close={$last->close}");
        }

        $test5m = $evaluator->evaluate5m($testSym, $testTs);
        $this->info("Test: {$testSym} @ {$testTs} — 5m bars: ".count($test5m->toArray()).' gates, empty: '.($test5m->isEmpty() ? 'YES' : 'NO'));
        if (! $test5m->isEmpty()) {
            $this->info('  notional='.($test5m->get('notional') ?? '?').' atr_pct='.($test5m->get('atr_pct') ?? '?').' rvol='.($test5m->get('rvol_ratio') ?? '?').' move30m='.($test5m->get('move_30m_pct') ?? '?'));
        }

        // RAW MySQL debug
        $table = (bool) $this->option('fulltable') ? 'five_minute_prices_full' : 'five_minute_prices';
        $rawCount = DB::table($table)->where('symbol', $testSym)->where('trading_date_est', substr($testTs, 0, 10))->count();
        $rawSample = DB::table($table)->where('symbol', $testSym)->where('trading_date_est', substr($testTs, 0, 10))->orderBy('ts_est')->limit(3)->get(['ts_est', 'price']);
        $this->info("  Raw MySQL: {$rawCount} rows, sample: ".json_encode($rawSample->toArray()));
        $this->line('');

        $bar = $this->output->createProgressBar(count($steps) * count($symbols));
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $candidates = [];

        foreach ($steps as $tsEst) {
            foreach ($symbols as $s) {
                $bar->setMessage("{$tsEst} {$s}");
                $bar->advance();

                $g5m = $evaluator->evaluate5m($s, $tsEst);
                if ($g5m->isEmpty()) {
                    continue;
                }

                // Debug: first symbol, first time — show ALL gates failing
                if ($s === $symbols[0] && $tsEst === $steps[0]) {
                    $this->line("\n<fg=yellow>Gate check for {$s} @ {$tsEst}:</>");
                    foreach ($versions as $v) {
                        $this->line("  Pipeline {$v['pipeline_letter']}:");
                        foreach ($v['gates_5m'] ?? [] as $gate => $cfg) {
                            $val = $g5m->toArray()[$gate] ?? null;
                            $min = $cfg['threshold_min'] ?? null;
                            $max = $cfg['threshold_max'] ?? null;
                            $passed = true;
                            if ($val === null) {
                                $passed = false;
                            } elseif ($min === null && $max === null) {
                                $passed = (bool) $val;
                            } elseif ($min !== null && $val < $min) {
                                $passed = false;
                            } elseif ($max !== null && $val > $max) {
                                $passed = false;
                            }
                            $this->line("    {$gate}: val={$val} min={$min} max={$max} → ".($passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'));
                        }
                    }
                    $this->line('');
                }

                foreach ($versions as $v) {
                    if (! $this->passes($g5m->toArray(), $v['gates_5m'] ?? [])) {
                        continue;
                    }
                    $score = $this->score($g5m->toArray(), $v['scanner_score_formula']);

                    // entry_score_min / entry_score_max gate (checked against computed score)
                    $scoreMin = $v['gates_5m']['entry_score_min']['threshold_min'] ?? null;
                    $scoreMax = $v['gates_5m']['entry_score_min']['threshold_max'] ?? null;
                    if ($scoreMin !== null && $score < (float) $scoreMin) {
                        continue;
                    }
                    if ($scoreMax !== null && $score > (float) $scoreMax) {
                        continue;
                    }

                    $stats[$v['pipeline_letter']]['signals']++;
                    $stats[$v['pipeline_letter']]['symbols'][$s] = true;

                    // Overwrite candidate with fresh timestamp
                    $candidates[$s][$v['id']] = [
                        'pipeline_letter' => $v['pipeline_letter'],
                        'signal_type' => $v['signal_type'],
                        'signal_ts_est' => $tsEst,
                        'score' => $score,
                    ];
                }

                $g1m = $evaluator->evaluate1m($s, $tsEst);
                if ($g1m->isEmpty()) {
                    continue;
                }

                foreach ($versions as $v) {
                    if (! isset($candidates[$s][$v['id']])) {
                        continue;
                    }

                    // Debug: show 1m gates for first candidate
                    if (! ($candidates[$s][$v['id']]['_debugged_1m'] ?? false)) {
                        $candidates[$s][$v['id']]['_debugged_1m'] = true;
                        $this->line("\n<fg=yellow>1m Gate check for {$s} @ {$tsEst}:</>");
                        $this->line("  Pipeline {$v['pipeline_letter']}:");
                        foreach ($v['gates_1m'] ?? [] as $gate => $cfg) {
                            $val = $g1m->toArray()[$gate] ?? null;
                            $min = $cfg['threshold_min'] ?? null;
                            $max = $cfg['threshold_max'] ?? null;
                            $passed = true;
                            if ($val === null) {
                                $passed = false;
                            } elseif ($min === null && $max === null) {
                                $passed = (bool) $val;
                            } elseif ($min !== null && $val < $min) {
                                $passed = false;
                            } elseif ($max !== null && $val > $max) {
                                $passed = false;
                            }
                            $this->line("    {$gate}: val={$val} min={$min} max={$max} → ".($passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'));
                        }
                    }

                    if (! $this->passes($g1m->toArray(), $v['gates_1m'] ?? [])) {
                        continue;
                    }

                    $c = $candidates[$s][$v['id']];
                    if (($c['_last_entry_ts'] ?? '') === $c['signal_ts_est']) {
                        continue;
                    }
                    $candidates[$s][$v['id']]['_last_entry_ts'] = $c['signal_ts_est'];

                    $entryType = 'UNCLASSIFIED';
                    $entryData = [
                        'entry_price' => $g1m->get('price') ?? 0,
                        'entry_type' => $entryType,
                        'entry_ts_est' => $tsEst,
                    ];

                    // Optionally use the real entry finder for accurate classification
                    if ($useEntryFinder) {
                        $finderClass = 'OneMinuteEntryFinder'.$v['version_string'];
                        $finderClass = str_replace('.', '_', 'V'.str_replace('v', '', $v['version_string']));
                        $fqcn = "App\\Services\\Trading\\OneMinuteEntryFinder{$finderClass}";
                        if (class_exists($fqcn)) {
                            try {
                                $finder = app($fqcn);
                                if (method_exists($finder, 'setFullTable')) {
                                    $finder->setFullTable((bool) $this->option('fulltable'));
                                }
                                $result = $finder->findBestLong($s, $c['signal_ts_est'], $tsEst);
                                if (! empty($result['best_entry'])) {
                                    $entryData = $result['best_entry'];
                                }
                            } catch (\Throwable) {
                            }
                        }
                    } else {
                        // Build entry data matching V1 doFindBestLong return shape
                        $price = $g1m->get('price') ?? 0;
                        $atr = $g1m->get('atr_1m') ?? $g1m->get('atr') ?? 0;
                        $atrMultiplier = \App\Services\TradingSettingService::getStopLossAtrMultiplier();
                        $stopPrice = round($price - ($atr * $atrMultiplier), 2);
                        $stopPrice = max($stopPrice, $price * 0.98);
                        $risk = $price - $stopPrice;
                        $riskPct = $price > 0 ? ($risk / $price) * 100 : 0;
                        $trailPct = max(0.7, min(1.0, ($price > 0 ? ($atr * $atrMultiplier / $price) * 100 : 0)));
                        $volRatio = $g1m->get('vol_ratio_1m') ?? $g1m->get('rvol_ratio') ?? 0;
                        $bodyPct = $g1m->get('body_pct') ?? 0;
                        $aboveVwapPct = $g1m->get('above_vwap_entry_pct') ?? $g1m->get('above_vwap_pct') ?? 0;
                        $roomToHodPct = $g1m->get('room_to_hod_pct') ?? 0;

                        $entryType = $classifier->classify($g1m->toArray());

                        // Grab ALL 1m gate values to populate ML feature columns
                        // that TradeAlertWriterV1 maps from $entry into trade_alerts
                        $g1mVals = $g1m->toArray();

                        $entryData = [
                            'entry_price' => round($price, 2),
                            'stop_loss' => round($stopPrice, 2),
                            'entry_type' => $entryType,
                            'entry_ts_est' => $tsEst,
                            'score' => round($c['score'] ?? 0, 3),
                            'risk_pct' => round($riskPct, 3),
                            'risk_per_share' => round($risk, 6),
                            'atr_pct' => $price > 0 ? round(($atr / $price) * 100, 3) : 0,
                            'atr' => round($atr, 2),
                            'suggested_trailing_stop' => round($price * ($trailPct / 100), 6),
                            'suggested_trailing_stop_pct' => round($trailPct, 3),
                            'targets' => [
                                '1R' => round($price + 1.0 * $risk, 6),
                                '2R' => round($price + 2.0 * $risk, 6),
                                '3R' => round($price + 3.0 * $risk, 6),
                            ],
                            'vwap' => round($g1m->get('price') ?? $price, 2),
                            'hod' => round($g1m->get('hod') ?? $price, 2),
                            'body_pct' => round($bodyPct, 4),
                            'vol_ratio' => round($volRatio, 2),
                            'above_vwap_pct' => round($aboveVwapPct, 3),
                            'room_to_run_pct' => round($roomToHodPct, 3),
                            // --- ML feature fields (map GateEvaluator names to entry field names) ---
                            'room_to_hod_pct' => $g1mVals['room_to_hod_pct'] ?? null,
                            'above_vwap_entry_pct' => $g1mVals['above_vwap_entry_pct'] ?? null,
                            'entry_body_pct' => $g1mVals['body_pct'] ?? null,
                            'entry_close_position' => $g1mVals['close_position'] ?? null,
                            'entry_volume_ratio' => $g1mVals['vol_ratio_1m'] ?? null,
                            'entry_notional_1m' => $g1mVals['notional_1m'] ?? null,
                            'rsi' => $g1mVals['rsi'] ?? null,
                            // 5m choppiness fields (from the 5m gates)
                            'five_min_directional_changes' => $g5m->get('directional_changes'),
                            'five_min_green_bar_pct' => $g5m->get('green_bar_pct'),
                            'five_min_net_progress' => $g5m->get('net_progress_pct'),
                            'consolidation_bars' => $g5m->get('consolidation_bars'),
                            'breakout_volume_ratio' => $g5m->get('breakout_volume_ratio'),
                            // Entry score sub-components (matches V1 computeEntryScoreComponents)
                            ...\App\Services\TradingV2\EntryTypeClassifier::computeScoreComponents(array_merge($g1mVals, ['ts_est' => $tsEst])),
                        ];
                    }

                    $stats[$v['pipeline_letter']]['entries']++;
                    $this->line('');
                    $this->line("<fg=green>▶ {$v['pipeline_letter']} {$s} @ {$tsEst} — {$entryType} — score: {$c['score']}</>");

                    if ($write) {
                        $writer = app(\App\Services\Trading\TradeAlertWriterV1::class);
                        $writer->setBacktestMode(true);
                        $result = $writer->upsertAlert(
                            signal: [
                                'symbol' => $s,
                                'asset_type' => 'stock',
                                'signal_type' => $c['signal_type'],
                                'signal_ts_est' => $c['signal_ts_est'],
                                'score' => $c['score'],
                                'atr' => $entryData['atr'] ?? $g1m->get('atr') ?? 0,
                                'atr_pct' => $entryData['atr_pct'] ?? $g1m->get('atr_pct') ?? 0,
                                'meta' => array_merge(
                                    // Map GateEvaluator 5m gate names to the field names
                                    // TradeAlertWriterV1::upsertAlert() reads from signal meta
                                    // for the ML feature columns (move_30m_pct, rvol_5m,
                                    // atr_pct_5m, notional_last5m, spy_move_30m_pct, etc).
                                    [
                                        'move_30m_pct' => $g5m->get('move_30m_pct'),
                                        'rvol_5m' => $g5m->get('rvol_ratio'),
                                        'atr_pct_5m' => $g5m->get('atr_pct'),
                                        'notional_last5m' => $g5m->get('notional'),
                                        'pct_nd' => $g5m->get('pct_nd'),
                                        'spy_move_30m_pct' => $g5m->get('benchmark_move_15m'),
                                        'universe_size' => $g5m->get('universe_size'),
                                    ],
                                    $g5m->toArray(),
                                    $g1m->toArray(),
                                ),
                            ],
                            entry: array_merge($entryData, ['query_source' => 'v2-backtest']),
                            asOfTsEst: $tsEst,
                            algorithmVersion: $v['version_string'],
                            pipelineRun: $v['pipeline_letter'],
                            isRealtime: false,
                        );
                        if ($result === false) {
                            $this->warn("  ↳ Write REJECTED for {$s}");
                        }
                    }
                }
            }
        }

        $bar->finish();
        $this->line('');
        $this->line('');
        $this->info(str_repeat('═', 60));
        $this->info(' BACKTEST RESULTS');
        $this->info(str_repeat('═', 60));
        $this->info("Window: {$from} → {$to} | Symbols: ".count($symbols));
        $this->line('');

        $this->table(
            ['Pipeline', 'Version', '5m Signals', '1m Entries', 'Symbols Hit'],
            array_map(function ($v) use ($stats) {
                $s = $stats[$v['pipeline_letter']];

                return [
                    $v['pipeline_letter'],
                    $v['version_string'],
                    $s['signals'],
                    $s['entries'],
                    count($s['symbols']),
                ];
            }, $versions)
        );

        return self::SUCCESS;
    }

    private function passes(array $gates, array $thresholds): bool
    {
        foreach ($thresholds as $gate => $cfg) {
            $value = $gates[$gate] ?? null;
            if ($value === null) {
                continue;
            }
            $min = $cfg['threshold_min'] ?? null;
            $max = $cfg['threshold_max'] ?? null;
            if ($min === null && $max === null) {
                if (! $value) {
                    return false;
                }

                continue;
            }
            if ($min !== null && $value < $min) {
                return false;
            }
            if ($max !== null && $value > $max) {
                return false;
            }
        }

        return true;
    }

    private function score(array $gates, ?string $formula): float
    {
        if (! $formula) {
            return 0.0;
        }

        $vars = [
            'move30m' => $gates['move_30m_pct'] ?? 0,
            'rvol' => $gates['rvol_ratio'] ?? 0,
            'rvolRatio' => $gates['rvol_ratio'] ?? 0,
            'atrPct' => $gates['atr_pct'] ?? 0,
            'greenDays' => $gates['higher_low_count'] ?? 0,
        ];

        $expr = str_replace(array_keys($vars), array_values($vars), $formula);
        $expr = preg_replace_callback('/min\s*\(\s*([\d.]+)\s*,\s*([\d.]+)\s*\)/', fn ($m) => (string) min((float) $m[1], (float) $m[2]), $expr);
        $expr = preg_replace('/[^0-9+\-*.()\/ ]/', '', $expr);

        try {
            $result = eval("return {$expr};");

            return is_numeric($result) ? round((float) $result, 3) : 0.0;
        } catch (\Throwable) {
            return 0.0;
        }
    }
}
