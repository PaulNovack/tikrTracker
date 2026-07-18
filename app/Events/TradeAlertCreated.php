<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TradeAlertCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $alert
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('trade-alerts'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'alert.created';
    }

    public function broadcastWith(): array
    {
        return [
            'symbol' => $this->alert['symbol'] ?? 'UNKNOWN',
            'asset_type' => $this->alert['asset_type'] ?? 'stock',
            'signal_type' => $this->alert['signal_type'] ?? 'UNKNOWN',
            'entry_type' => $this->alert['entry_type'] ?? 'UNKNOWN',
            'entry' => $this->alert['entry'] ?? '0.00',
            'stop' => $this->alert['stop'] ?? '0.00',
            'risk_pct' => $this->alert['risk_pct'] ?? '0.00',
            'score' => $this->alert['score'] ?? '0.00',
            'targets' => json_decode($this->alert['targets'] ?? '[]', true),
            'signal_ts_est' => $this->alert['signal_ts_est'] ?? now()->toISOString(),
            'entry_ts_est' => $this->alert['entry_ts_est'] ?? now()->toISOString(),
            'ml_win_prob' => $this->alert['ml_win_prob'] ?? null,
            'ml_scored_at' => $this->alert['ml_scored_at'] ?? null,
            'ml_model_version' => $this->alert['ml_model_version'] ?? null,
            'is_realtime' => (bool) ($this->alert['is_realtime'] ?? false),
            'created_at' => now()->toISOString(),
        ];
    }
}
