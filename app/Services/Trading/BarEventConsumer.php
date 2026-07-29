<?php

namespace App\Services\Trading;

use App\Contracts\Trading\OneMinuteEntryFinderContract;
use App\Services\TradingSettingService;
use Illuminate\Support\Facades\Redis;

/**
 * BarEventConsumer — consumes bar events from rt:events:bars Redis Stream
 * and runs per-symbol scanning per the Redis_Real_Time_Bar_Migration_Plan.pdf.
 *
 * Event flow:
 *   - 5m_bar → scanner → if signal passes → store active candidate (step 6-7)
 *   - 1m_bar → check candidate → entry finder → upsertAlert (step 8-9)
 *
 * Usage:
 *   php artisan bar-events:consume --group=scanner-v25 --batch=100
 */
class BarEventConsumer
{
    private const STREAM_KEY = 'rt:events:bars';

    private const CANDIDATE_PREFIX = 'rt:candidate:';

    private const LOCK_PREFIX = 'rt:alert-lock:';

    private const MSG_ID_KEY = '>';

    public function __construct(
        private readonly AbstractSignalScanner $scanner,
        private readonly OneMinuteEntryFinderContract $finder,
        private readonly ?TradeAlertWriterV1 $writer = null,
    ) {}

    /**
     * Run the consumer loop — blocks on XREADGROUP for new bar events.
     *
     * Uses Predis' executeRaw() to bypass command-profile argument
     * restructuring. All arguments are sent as flat RESP strings directly
     * to Redis, avoiding the format mismatches in Predis' XREADGROUP and
     * XGROUP command classes.
     */
    public function run(string $group, string $consumer, int $batchSize = 100): void
    {
        $this->ensureGroup($group);
        $client = Redis::connection('rt')->client();

        \Log::channel('bar-events')->info('[BarConsumer] Consumer started', [
            'group' => $group,
            'consumer' => $consumer,
        ]);

        // Fast-forward: skip past any stale backlog by setting the consumer group
        // to the newest message in the stream. This prevents the consumer from
        // slogging through hours of old events when starting up after a restart.
        $this->fastForwardGroup($group, $client);

        while (true) {
            $error = false;
            $messages = $client->executeRaw([
                'XREADGROUP', 'GROUP', $group, $consumer,
                'COUNT', $batchSize,
                'BLOCK', 5000,
                'STREAMS', self::STREAM_KEY, self::MSG_ID_KEY,
            ], $error);

            if ($error || empty($messages)) {
                continue;
            }

            // Heartbeat silenced to reduce log noise

            // Predis returns: [['streamName', [['id', ['field1', 'val1', ...]]]]]
            foreach ($messages as $streamEntry) {
                if (! is_array($streamEntry) || count($streamEntry) < 2) {
                    continue;
                }
                [$stream, $entries] = $streamEntry;
                if (! is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    $id = $entry[0] ?? null;
                    $rawFields = $entry[1] ?? [];

                    if ($id === null) {
                        continue;
                    }

                    // Convert [field1, val1, field2, val2, ...] to assoc array
                    $fields = [];
                    $rawCount = count($rawFields);
                    for ($i = 0; $i + 1 < $rawCount; $i += 2) {
                        $fields[$rawFields[$i]] = $rawFields[$i + 1];
                    }

                    $this->handleMessage($id, $fields);
                    Redis::connection('rt')->xack(self::STREAM_KEY, $group, $id);
                }
            }
        }
    }

    /**
     * Dispatch to handler based on event type.
     */
    private function handleMessage(string $id, array $fields): void
    {
        $symbol = $fields['symbol'] ?? '';
        $epoch = (int) ($fields['epoch'] ?? 0);
        $tsEst = $fields['ts_est'] ?? '';
        $eventType = $fields['type'] ?? '';

        if ($symbol === '' || $epoch === 0) {
            \Log::channel('bar-events')->debug('[BarConsumer] Skipping invalid event', [
                'symbol' => $symbol,
                'epoch' => $epoch,
                'type' => $eventType,
            ]);

            return;
        }

        // Respect the per-pipeline run_cron setting — if the pipeline is turned
        // off in /trading-settings, don't process any events for it.
        $pipelineLetter = $this->scanner->getPipelineLetter();
        if (! TradingSettingService::isPipelineRunCronEnabled($pipelineLetter)) {
            return;
        }

        // Discard events older than 10 minutes to prevent stale alert creation
        // when the consumer falls behind on the Redis stream.
        $ageSeconds = time() - $epoch;
        if ($ageSeconds > 600) {
            \Log::channel('bar-events')->debug('[BarConsumer] Discarding stale event', [
                'symbol' => $symbol,
                'type' => $eventType,
                'epoch' => $epoch,
                'age_seconds' => $ageSeconds,
            ]);

            return;
        }

        if ($eventType === '5m_bar') {
            $this->handle5mBar($symbol, $epoch, $tsEst);
        } elseif ($eventType === '1m_bar') {
            $this->handle1mBar($symbol, $epoch, $tsEst);
        } else {
            \Log::channel('bar-events')->debug('[BarConsumer] Unknown event type', ['type' => $eventType, 'symbol' => $symbol]);
        }
    }

    /**
     * 5m bar → scan → if signal → store candidate (PDF §3.1 step 6-7)
     */
    private function handle5mBar(string $symbol, int $epoch, string $tsEst): void
    {
        $version = $this->scanner->getVersion();
        $lockKey = self::LOCK_PREFIX.$version.':'.$symbol.':'.$epoch.':5m_scan';
        if (! Redis::connection('rt')->set($lockKey, '1', 'NX', 'EX', 600)) {
            return;
        }

        $signal = $this->scanner->scanSymbol($symbol, $tsEst);
        if ($signal === null) {
            return;
        }

        \Log::channel('bar-events')->info('[BarConsumer] 5m signal found', [
            'symbol' => $symbol,
            'version' => $version,
            'score' => $signal['score'],
            'ts' => $tsEst,
        ]);

        $candidateKey = self::CANDIDATE_PREFIX.$version.':stock:'.$symbol;
        Redis::connection('rt')->setex($candidateKey, 120, json_encode([
            'symbol' => $symbol,
            'signal_ts_est' => $tsEst,
            'signal_epoch' => $epoch,
            'score' => $signal['score'],
            'atr' => $signal['atr'],
            'atr_pct' => $signal['atr_pct'],
            'signal_type' => $signal['signal_type'],
            'meta' => $signal['meta'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * 1m bar → check candidate → entry finder → write alert (PDF §3.1 step 8-9)
     */
    private function handle1mBar(string $symbol, int $epoch, string $tsEst): void
    {
        $version = $this->scanner->getVersion();
        $candidateKey = self::CANDIDATE_PREFIX.$version.':stock:'.$symbol;
        $candidateJson = Redis::connection('rt')->get($candidateKey);
        if (! $candidateJson) {
            return;
        }

        $candidate = json_decode($candidateJson, true);
        if (! is_array($candidate)) {
            return;
        }

        // Discard candidates older than 10 minutes — the signal is too stale to trade.
        $candidateEpoch = (int) ($candidate['signal_epoch'] ?? 0);
        if ($candidateEpoch > 0 && (time() - $candidateEpoch) > 600) {
            Redis::connection('rt')->del($candidateKey);
            \Log::channel('bar-events')->debug('[BarConsumer] Discarding stale candidate', [
                'symbol' => $symbol,
                'version' => $version,
                'candidate_age_seconds' => time() - $candidateEpoch,
            ]);

            return;
        }

        $lockKey = self::LOCK_PREFIX.$version.':'.$symbol.':'.$epoch.':1m_entry';
        if (! Redis::connection('rt')->set($lockKey, '1', 'NX', 'EX', 300)) {
            return;
        }

        $entryResult = $this->finder->findBestLong($symbol, $candidate['signal_ts_est'], $tsEst);
        if (empty($entryResult['best_entry'])) {
            \Log::channel('bar-events')->info('[BarConsumer] 1m candidate, no entry', [
                'symbol' => $symbol, 'version' => $version, 'ts' => $tsEst,
            ]);

            return;
        }

        // Guard: reject entries with missing or zero-valued key fields before
        // forwarding to the writer, to avoid silent rejections downstream.
        $best = $entryResult['best_entry'];
        $entryPrice = $best['entry_price'] ?? $best['entry'] ?? 0;
        $entryType = $best['entry_type'] ?? $best['type'] ?? null;
        if ($entryPrice <= 0 || empty($entryType)) {
            \Log::channel('bar-events')->warning('[BarConsumer] Rejecting malformed best_entry from finder', [
                'symbol' => $symbol,
                'version' => $version,
                'entry_price' => $entryPrice,
                'entry_type' => $entryType,
                'best_entry_keys' => array_keys($best),
            ]);

            return;
        }

        if ($this->writer !== null) {
            $alertId = $this->writer->upsertAlert(
                signal: [
                    'symbol' => $symbol,
                    'asset_type' => 'stock',
                    'signal_type' => $candidate['signal_type'],
                    'signal_ts_est' => $candidate['signal_ts_est'],
                    'score' => $candidate['score'],
                    'atr' => $candidate['atr'],
                    'atr_pct' => $candidate['atr_pct'],
                    'meta' => $candidate['meta'] ?? [],
                ],
                entry: array_merge($best, [
                    'query_source' => 'redis',
                    'entry_ts_est' => $best['entry_ts_est'] ?? $tsEst,
                    'entry_meta' => $best['entry_meta'] ?? [],
                ]),
                asOfTsEst: $tsEst,
                algorithmVersion: $version,
                pipelineRun: $this->scanner->getPipelineLetter(),
                isRealtime: true
            );

            \Log::channel('bar-events')->info('[BarConsumer] Alert written', [
                'symbol' => $symbol,
                'version' => $version,
                'pipeline' => $this->scanner->getPipelineLetter(),
                'alert_id' => $alertId,
                'entry_price' => $best['entry_price'] ?? $best['entry'] ?? 0,
                'entry_type' => $best['entry_type'] ?? $best['type'] ?? 'N/A',
                'ts' => $tsEst,
            ]);

            if ($alertId === false || $alertId === 0) {
                \Log::channel('bar-events')->warning('[BarConsumer] Alert REJECTED by writer', [
                    'symbol' => $symbol,
                    'version' => $version,
                    'pipeline' => $this->scanner->getPipelineLetter(),
                    'entry_price' => $best['entry_price'] ?? $best['entry'] ?? 0,
                    'entry_type' => $best['entry_type'] ?? $best['type'] ?? 'N/A',
                    'ts' => $tsEst,
                    'check_stale_alerts_log' => 'See stale-alerts log channel for exact rejection reason from TradeAlertWriter',
                ]);
            }
        }
    }

    /**
     * Ensure the consumer group exists on the stream.
     *
     * Uses executeRaw() to bypass Predis command-profile arg restructuring.
     */
    private function ensureGroup(string $group): void
    {
        $client = Redis::connection('rt')->client();

        try {
            $client->executeRaw([
                'XGROUP', 'CREATE', self::STREAM_KEY, $group,
                '$', 'MKSTREAM',
            ]);
        } catch (\Throwable) {
            // Group already exists
        }
    }

    /**
     * Fast-forward the consumer group to the newest message in the stream.
     * This skips past any stale backlog so the consumer starts processing
     * current events immediately instead of slogging through hours of
     * old bars from when the stream was backed up.
     */
    private function fastForwardGroup(string $group, mixed $client): void
    {
        try {
            $reply = $client->executeRaw(['XREVRANGE', self::STREAM_KEY, '+', '-', 'COUNT', 1]);
            if (! empty($reply) && isset($reply[0][0])) {
                $newestId = $reply[0][0];
                $client->executeRaw([
                    'XGROUP', 'SETID', self::STREAM_KEY, $group, $newestId,
                ]);
                \Log::channel('bar-events')->info('[BarConsumer] Fast-forwarded group to newest message', [
                    'group' => $group,
                    'newest_id' => $newestId,
                ]);
            }
        } catch (\Throwable $e) {
            \Log::channel('bar-events')->warning('[BarConsumer] Failed to fast-forward group', [
                'group' => $group,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
