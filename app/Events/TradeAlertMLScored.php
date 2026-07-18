<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TradeAlertMLScored implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $alertId,
        public string $symbol,
        public float $mlWinProb,
        public string $mlModelVersion,
        public string $tableName = 'trade_alerts',
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('trade-alerts'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'alert.ml-scored';
    }

    public function broadcastWith(): array
    {
        return [
            'alert_id' => $this->alertId,
            'symbol' => $this->symbol,
            'ml_win_prob' => $this->mlWinProb,
            'ml_model_version' => $this->mlModelVersion,
            'scored_at' => now()->toISOString(),
        ];
    }
}
