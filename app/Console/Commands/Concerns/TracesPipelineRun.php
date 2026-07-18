<?php

namespace App\Console\Commands\Concerns;

use App\Services\PipelineTracer;

/**
 * Drop this trait into any pipeline command to get full millisecond tracing.
 *
 * Usage:
 *   use TracesPipelineRun;
 *
 *   // At the top of handle(), after resolving asOfTsEst:
 *   $tracer = $this->startTrace('A', $asOfTsEst);  // live mode only
 *
 *   // After scanner:
 *   $tracer?->checkpoint('SCANNER_DONE', ['signals' => count($signals)]);
 *
 *   // After each alert is written:
 *   $tracer?->alertWritten($alertId, $symbol, $entryTsEst, $signalTsEst);
 *
 *   // At end of handle():
 *   $tracer?->finish(['alerts_written' => $n]);
 */
trait TracesPipelineRun
{
    private ?PipelineTracer $pipelineTracer = null;

    /**
     * Start a tracer for live (non-backtest) runs only.
     * Returns null during backtests so callers can safely use ?-> syntax.
     */
    protected function startTrace(string $pipelineLetter, string $asOfTsEst): ?PipelineTracer
    {
        if ($this->option('backtest') || $this->option('rolling-window')) {
            return null;
        }

        $this->pipelineTracer = PipelineTracer::start($pipelineLetter, $asOfTsEst);

        return $this->pipelineTracer;
    }
}
