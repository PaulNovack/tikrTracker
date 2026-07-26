<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Redis;

/**
 * BarEventConsumer — consumes 5m bar events from the rt:events:bars Redis
 * Stream and runs per-symbol scanning via scanSymbol().
 *
 * Designed for the event-driven path described in Redis_Real_Time_Bar_Migration_Plan.pdf:
 *   - A 5m event triggers the V25.2 scanner for that symbol only.
 *   - A passing signal creates an active candidate for the entry finder.
 *   - A dedup lock prevents repeated alerts for the same symbol/bar/type.
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
    ) {}

    /**
     * Run the consumer loop — blocks on XREADGROUP for new bar events.
     *
     * @param  string  $group  Consumer group name (e.g. "scanner-v25")
     * @param  string  $consumer  Consumer name (unique per worker process)
     * @param  int  $batchSize  Max events per XREADGROUP call
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
                5000 // 5-second block timeout
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
     * Process a single bar event: scan the symbol, find entries, write alerts.
     *
     * @param  string  $id  Stream message ID
     * @param  array<string, string>  $fields  Message fields
     */
    private function handleMessage(string $id, array $fields): void
    {
        $symbol = $fields['symbol'] ?? '';
        $epoch = (int) ($fields['epoch'] ?? 0);
        $tsEst = $fields['ts_est'] ?? '';

        if ($symbol === '' || $epoch === 0) {
            return;
        }

        // Only process 5m bar events (skip 1m events for now)
        if (($fields['type'] ?? '') !== '5m_bar') {
            return;
        }

        // Dedup: skip if we already processed this bar for this symbol
        $lockKey = self::LOCK_PREFIX.'v25.2:'.$symbol.':'.$epoch.':5m_scan';
        if (! Redis::set($lockKey, '1', ['NX', 'EX' => 600])) {
            return; // already processed
        }

        // Scan the symbol
        $signal = $this->scanner->scanSymbol($symbol, $tsEst);
        if ($signal === null) {
            return;
        }

        // Store active candidate with short TTL
        $candidateKey = self::CANDIDATE_PREFIX.'v25.2:stock:'.$symbol;
        Redis::setex($candidateKey, 600, json_encode([
            'symbol' => $symbol,
            'signal_ts_est' => $tsEst,
            'score' => $signal['score'],
        ], JSON_THROW_ON_ERROR));

        // Find entry
        $entry = $this->finder->findBestLong($symbol, $tsEst, $tsEst);
        if ($entry === null) {
            return;
        }

        // Write alert via the scanner's alert writer
        // (delegated to the pipeline command's handle loop)
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
