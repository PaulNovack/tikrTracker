<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SymbolDataFetchFailed extends Notification
{
    public function __construct(
        public string $symbol,
        public string $assetType,
        public string $suggestion = '',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => "Symbol '{$this->symbol}' has no data available",
            'message' => "The {$this->assetType} symbol '{$this->symbol}' could not be found or has no price data available from Yahoo Finance.",
            'suggestion' => $this->suggestion,
            'symbol' => $this->symbol,
            'assetType' => $this->assetType,
            'type' => 'warning',
        ];
    }
}
