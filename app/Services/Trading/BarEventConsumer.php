<?php

namespace App\Services\Trading;

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
        private readonly AbstractOneMinuteEntryFinder $finder,
        private readonly ?TradeAlertWriterV1 $writer = null,
    ) {}

    /**
     * Run the consumer loop — blocks on XREADGROUP for new bar events.
     */
    public function run(string $group, string $consumer, int $batchSize = 100): void
    {
        $this->ensureGroup($group);

        while (true) {
            $messages = Redis::xreadgroup(
                $group,
                $consumer,
                [self::STREAM_KEY => self::MSG_ID_KEY],
                $batchSize,
                5000
            );

            if (! $messages) {
                continue;
            }

            foreach ($messages as $stream => $entries) {
                foreach ($entries as $id => $fields) {
                    $this->handleMessage($id, $fields);
                    Redis::xack(self::STREAM_KEY, $group, [$id]);
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
            return;
        }

        if ($eventType === '5m_bar') {
            $this->handle5mBar($symbol, $epoch, $tsEst);
        } elseif ($eventType === '1m_bar') {
            $this->handle1mBar($symbol, $epoch, $tsEst);
        }
    }

    /**
     * 5m bar → scan → if signal → store candidate (PDF §3.1 step 6-7)
     */
    private function handle5mBar(string $symbol, int $epoch, string $tsEst): void
    {
        $version = $this->scanner->getVersion();
        $lockKey = self::LOCK_PREFIX.$version.':'.$symbol.':'.$epoch.':5m_scan';
        if (! Redis::set($lockKey, '1', ['NX', 'EX' => 600])) {
            return;
        }

        $signal = $this->scanner->scanSymbol($symbol, $tsEst);
        if ($signal === null) {
            return;
        }

        $candidateKey = self::CANDIDATE_PREFIX.$version.':stock:'.$symbol;
        Redis::setex($candidateKey, 600, json_encode([
            'symbol' => $symbol,
            'signal_ts_est' => $tsEst,
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
        $candidateJson = Redis::get($candidateKey);
        if (! $candidateJson) {
            return;
        }

        $candidate = json_decode($candidateJson, true);
        if (! is_array($candidate)) {
            return;
        }

        $lockKey = self::LOCK_PREFIX.$version.':'.$symbol.':'.$epoch.':1m_entry';
        if (! Redis::set($lockKey, '1', ['NX', 'EX' => 300])) {
            return;
        }

        $entryResult = $this->finder->findBestLong($symbol, $candidate['signal_ts_est'], $tsEst);
        if (empty($entryResult['best_entry'])) {
            return;
        }

        if ($this->writer !== null) {
            $best = $entryResult['best_entry'];
            $this->writer->upsertAlert(
                signal: [
                    'symbol' => $symbol,
                    'signal_type' => $candidate['signal_type'],
                    'signal_ts_est' => $candidate['signal_ts_est'],
                    'score' => $candidate['score'],
                    'atr' => $candidate['atr'],
                    'atr_pct' => $candidate['atr_pct'],
                    'meta' => $candidate['meta'] ?? [],
                ],
                entry: [
                    'entry_type' => $best['entry_type'] ?? $best['type'] ?? 'MOMO_1M',
                    'entry_ts_est' => $best['entry_ts_est'] ?? $tsEst,
                    'entry_price' => $best['entry_price'] ?? $best['entry'] ?? 0,
                    'stop_loss' => $best['stop_loss'] ?? $best['stop'] ?? 0,
                    'risk_pct' => (float) ($best['risk_pct'] ?? 2.0),
                    'entry_meta' => $best['entry_meta'] ?? [],
                ],
                asOfTsEst: $tsEst,
                algorithmVersion: $version,
                pipelineRun: $this->scanner->getPipelineLetter(),
                isRealtime: true
            );
        }
    }

    /**
     * Ensure the consumer group exists on the stream.
     */
    private function ensureGroup(string $group): void
    {
        try {
            Redis::xgroup('CREATE', self::STREAM_KEY, $group, '0', true);
        } catch (\Throwable) {
            // MKSTREAM + group already exists
        }
    }
}
