<?php

namespace App\Services\Trading\Realtime;

use App\Models\TradeAlert;
use Illuminate\Support\Facades\Log;

class RealtimeIntegrationDispatcherService
{
    /**
     * Dispatch after a trade_alert is created.
     * The alert is already in the DB (created by RealtimeTradeAlertFactoryService).
     * We just need to trigger ML scoring and any listeners.
     */
    public function dispatchAfterAlertCreated(TradeAlert $alert): void
    {
        // The alert was already inserted into trade_alerts by the factory.
        // Dispatch ML scoring job (if configured) to score this alert.
        $this->dispatchScoreJob($alert) || $this->dispatchCreatedEvent($alert);
    }

    /**
     * POST the alert to the internal C++ trade signal endpoint.
     * This reuses the full TradeAlertWriterV1 pipeline including deduplication,
     * position sizing, and ML job dispatch.
     */
    private function dispatchScoreJob(TradeAlert $alert): bool
    {
        $class = config('trading_realtime.score_job_class');

        if (! $class || ! is_string($class) || ! class_exists($class)) {
            return false;
        }

        $pipelineRun = 'R';

        try {
            if (method_exists($class, 'dispatch')) {
                $class::dispatch($alert->id, 'trade_alerts', $pipelineRun);
            } else {
                dispatch(new $class($alert->id, 'trade_alerts', $pipelineRun));
            }

            Log::info('Realtime trade alert score job dispatched', [
                'alert_id' => $alert->id,
                'job_class' => $class,
                'pipeline_run' => $pipelineRun,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Realtime score job dispatch failed', [
                'alert_id' => $alert->id,
                'job_class' => $class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function dispatchCreatedEvent(TradeAlert $alert): bool
    {
        $class = config('trading_realtime.created_event_class');

        if (! $class || ! is_string($class) || ! class_exists($class)) {
            return false;
        }

        try {
            event(new $class($alert));

            Log::info('Realtime trade alert event dispatched', [
                'alert_id' => $alert->id,
                'event_class' => $class,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Realtime created event dispatch failed', [
                'alert_id' => $alert->id,
                'event_class' => $class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
