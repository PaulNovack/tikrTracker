<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Millisecond-precision pipeline run tracer.
 *
 * Every pipeline run gets a unique $runId so you can grep the log and follow
 * one execution from bar-close → scanner → finder → writer → listener → order.
 *
 * Log format (pipeline-timing channel):
 *   [PIPE:A run:abc123] STAGE | key=value key=value ...
 *
 * Usage in a pipeline command:
 *   $tracer = PipelineTracer::start('A', $asOfTsEst);
 *   // ... scanner ...
 *   $tracer->checkpoint('SCANNER_DONE', ['signals' => count($signals), ...]);
 *   // ... per alert ...
 *   $tracer->alertWritten($alertId, $symbol, $entryTs);
 *   $tracer->finish(['alerts_written' => $n]);
 */
class PipelineTracer
{
    private string $runId;

    private float $t0;

    private float $tLast;

    private string $pipeline;

    private string $asOfTsEst;

    private function __construct(string $pipeline, string $asOfTsEst)
    {
        $this->pipeline = strtoupper($pipeline);
        $this->asOfTsEst = $asOfTsEst;
        $this->runId = substr(Str::uuid()->toString(), 0, 8);
        $this->t0 = microtime(true);
        $this->tLast = $this->t0;
    }

    /**
     * Create a new tracer and immediately log the START checkpoint.
     */
    public static function start(string $pipeline, string $asOfTsEst): self
    {
        $tracer = new self($pipeline, $asOfTsEst);

        $wallNow = now('America/New_York');
        $asOfEpoch = strtotime($asOfTsEst);
        $wallEpoch = $wallNow->getTimestamp();

        $tracer->log('START', [
            'pid' => getmypid(),
            'asOf' => $asOfTsEst,
            'wall_clock' => $wallNow->format('Y-m-d H:i:s.').str_pad((int) ($wallNow->micro / 1000), 3, '0', STR_PAD_LEFT),
            'asOf_to_wall_sec' => round($wallEpoch - $asOfEpoch, 3),
        ]);

        return $tracer;
    }

    /**
     * Log a named checkpoint with elapsed times.
     *
     * @param  array<string, mixed>  $context
     */
    public function checkpoint(string $stage, array $context = []): void
    {
        $now = microtime(true);
        $delta = round(($now - $this->tLast) * 1000);
        $total = round(($now - $this->t0) * 1000);

        $this->tLast = $now;

        $this->log($stage, array_merge([
            'delta_ms' => $delta,
            'total_ms' => $total,
        ], $context));
    }

    /**
     * Log when an alert is written so the alert_id is traceable.
     */
    public function alertWritten(int|string $alertId, string $symbol, string $entryTsEst, string $signalTsEst): void
    {
        $now = microtime(true);
        $wallNow = now('America/New_York');
        $entryAge = round($wallNow->getTimestamp() - strtotime($entryTsEst), 1);
        $signalAge = round($wallNow->getTimestamp() - strtotime($signalTsEst), 1);

        $this->log('ALERT_WRITTEN', [
            'alert_id' => $alertId,
            'symbol' => $symbol,
            'entry_ts' => $entryTsEst,
            'signal_ts' => $signalTsEst,
            'entry_age_sec' => $entryAge,
            'signal_age_sec' => $signalAge,
            'total_ms' => round(($now - $this->t0) * 1000),
        ]);
    }

    /**
     * Log the final COMPLETE checkpoint.
     *
     * @param  array<string, mixed>  $context
     */
    public function finish(array $context = []): void
    {
        $now = microtime(true);
        $wallNow = now('America/New_York');

        $this->log('COMPLETE', array_merge([
            'total_ms' => round(($now - $this->t0) * 1000),
            'wall_clock_end' => $wallNow->format('Y-m-d H:i:s'),
            'asOf_to_wall_sec' => round($wallNow->getTimestamp() - strtotime($this->asOfTsEst), 1),
        ], $context));
    }

    /**
     * Return the run ID so it can be stored on alerts or passed to other services.
     */
    public function getRunId(): string
    {
        return $this->runId;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $stage, array $context): void
    {
        Log::channel('pipeline-timing')->info(
            "[PIPE:{$this->pipeline} run:{$this->runId}] {$stage}",
            $context
        );
    }
}
