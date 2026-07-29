<?php

namespace App\Services\TradingV2;

use App\Services\TradingV2\Jobs\EvaluateBarJob;
use App\Services\TradingV2\Repositories\AlertVersionRepository;
use Illuminate\Support\Facades\Redis;

/**
 * Reads bar events from rt:events:bars stream, calls GateEvaluator once,
 * dispatches EvaluateBarJob with all gate values and version configs.
 */
class BarEventConsumer
{
    private const STREAM_KEY = 'rt:events:bars';

    public function __construct(
        private readonly GateEvaluator $evaluator,
        private readonly AlertVersionRepository $versionRepo,
    ) {}

    public function run(string $group, string $consumer, int $batchSize = 200): void
    {
        $client = Redis::connection()->client();
        $this->ensureGroup($group, $client);

        while (true) {
            try {
                $error = false;
                $messages = $client->executeRaw([
                    'XREADGROUP', 'GROUP', $group, $consumer,
                    'COUNT', $batchSize,
                    'BLOCK', 5000,
                    'STREAMS', self::STREAM_KEY, '>',
                ], $error);

                if ($error || empty($messages)) {
                    continue;
                }

                foreach ($messages as $streamEntry) {
                    if (! is_array($streamEntry) || count($streamEntry) < 2) {
                        continue;
                    }
                    [, $entries] = $streamEntry;
                    if (! is_array($entries)) {
                        continue;
                    }

                    foreach ($entries as $entry) {
                        $id = $entry[0] ?? null;
                        $rawFields = $entry[1] ?? [];
                        if ($id === null) {
                            continue;
                        }

                        $fields = [];
                        $rawCount = count($rawFields);
                        for ($i = 0; $i + 1 < $rawCount; $i += 2) {
                            $fields[$rawFields[$i]] = $rawFields[$i + 1];
                        }

                        $this->handleMessage($id, $fields);
                        Redis::xack(self::STREAM_KEY, $group, $id);
                    }
                }
            } catch (\Throwable $e) {
                \Log::channel('bar-events')->error('[BarConsumer] Connection error, reconnecting in 1s', [
                    'error' => $e->getMessage(),
                ]);
                sleep(1);
                $client = Redis::connection()->client();
            }
        }
    }

    private function handleMessage(string $id, array $fields): void
    {
        $symbol = $fields['symbol'] ?? '';
        $epoch = (int) ($fields['epoch'] ?? 0);
        $tsEst = $fields['ts_est'] ?? '';
        $eventType = $fields['type'] ?? '';

        if ($symbol === '' || $epoch === 0) {
            return;
        }

        // Discard events older than 10 minutes
        if (time() - $epoch > 600) {
            return;
        }

        // ── ONE computation for ALL versions ──
        $gates = $eventType === '5m_bar'
            ? $this->evaluator->evaluate5m($symbol, $tsEst)
            : $this->evaluator->evaluate1m($symbol, $tsEst);

        if ($gates->isEmpty()) {
            return;
        }

        // ── ONE job, ALL versions ──
        dispatch(new EvaluateBarJob(
            symbol: $symbol,
            tsEst: $tsEst,
            timeframe: $eventType === '5m_bar' ? '5m' : '1m',
            gates: $gates->toArray(),
            versions: $this->versionRepo->getActive(),
        ))->onQueue('gate-check');
    }

    private function ensureGroup(string $group, $client): void
    {
        try {
            $client->executeRaw([
                'XGROUP', 'CREATE', self::STREAM_KEY, $group, '0', 'MKSTREAM',
            ]);
        } catch (\Throwable) {
            // Group already exists — expected
        }
    }
}
