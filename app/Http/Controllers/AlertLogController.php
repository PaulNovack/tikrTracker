<?php

namespace App\Http\Controllers;

use App\Models\AlertLog;
use App\Models\AssetInfo;
use Inertia\Inertia;
use Inertia\Response;

class AlertLogController extends Controller
{
    public function index(): Response
    {
        $logs = AlertLog::query()
            ->where('user_id', auth()->id())
            ->latest('sent_at')
            ->paginate(50)
            ->through(fn ($log) => [
                'id' => $log->id,
                'symbol' => $log->symbol,
                'asset_id' => $this->getAssetIdForSymbol($log->symbol),
                'direction' => $log->direction,
                'trigger_price' => (float) $log->trigger_price,
                'current_price' => (float) $log->current_price,
                'trigger_percentage' => (float) $log->trigger_percentage,
                'change_percentage' => (float) $log->trigger_percentage,
                'email_status' => $log->email_status,
                'email_error' => $log->email_error,
                'sent_at' => $log->sent_at->toIso8601String(),
                'user_id' => $log->user_id,
                'price_alert_id' => $log->price_alert_id,
                'created_at' => $log->created_at->toIso8601String(),
                'updated_at' => $log->updated_at->toIso8601String(),
            ]);

        return Inertia::render('AlertLogs/index', [
            'logs' => $logs,
        ]);
    }

    private function getAssetIdForSymbol(string $symbol): ?int
    {
        $asset = AssetInfo::where('symbol', $symbol)->first();

        return $asset?->id;
    }
}
