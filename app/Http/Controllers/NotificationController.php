<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class NotificationController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        $notifications = auth()->user()
            ->notifications()
            ->with('asset') // Include asset relationship
            ->latest()
            ->paginate(50); // Show 50 notifications per page

        return Inertia::render('notifications', [
            'notifications' => $notifications,
        ]);
    }

    public function counts(): JsonResponse
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json(['read' => 0, 'unread' => 0]);
        }

        // More aggressive caching for performance: 10 minutes instead of 5
        $cacheKey = sprintf('notification-counts:%d', $user->id);
        $counts = \Illuminate\Support\Facades\Cache::remember($cacheKey, 600, function () use ($user) {
            // Optimize queries: use single query with conditional aggregation
            $result = \Illuminate\Support\Facades\DB::table('notifications')
                ->selectRaw('
                    SUM(CASE WHEN `read` = 0 THEN 1 ELSE 0 END) as unread_count,
                    SUM(CASE WHEN `read` = 1 THEN 1 ELSE 0 END) as read_count
                ')
                ->where('user_id', $user->id)
                ->whereNull('deleted_at') // Handle soft deletes efficiently
                ->first();

            return [
                'read' => (int) ($result->read_count ?? 0),
                'unread' => (int) ($result->unread_count ?? 0),
            ];
        });

        return response()->json($counts);
    }

    public function settings(): \Inertia\Response
    {
        $priceAlerts = auth()->user()
            ->priceAlerts()
            ->with('asset')
            ->get();

        $watchedAssets = auth()->user()
            ->watches()
            ->with('asset')
            ->get()
            ->map(function ($watch) {
                $asset = $watch->asset;

                // Get the most recent 5-minute price (today)
                $todayStart = now('UTC')->startOfDay();
                $latestPrice = $asset->fiveMinutePrices()
                    ->where('ts', '>=', $todayStart)
                    ->latest('ts')
                    ->first();

                // Fallback to latest daily price if no 5-minute data today
                if (! $latestPrice) {
                    $latestPrice = $asset->dailyPrices()
                        ->mondayOnly()
                        ->latest('date')
                        ->first();
                }

                return [
                    'id' => $asset->id,
                    'asset' => $asset, // Include full asset object for linking
                    'symbol' => $asset->symbol,
                    'common_name' => $asset->common_name,
                    'current_price' => $latestPrice ? (float) $latestPrice->price : null,
                ];
            });

        return Inertia::render('notifications-settings', [
            'priceAlerts' => $priceAlerts,
            'watchedAssets' => $watchedAssets,
            'defaultUpPercentage' => config('app.watch_default_up_pct', 2.5),
            'defaultDownPercentage' => config('app.watch_default_down_pct', 2.5),
        ]);
    }

    public function markAsRead(Notification $notification): RedirectResponse
    {
        $this->authorize('update', $notification);
        $notification->markAsRead();

        // Invalidate the notification counts cache
        $cacheKey = sprintf('notification-counts:%d', auth()->id());
        \Illuminate\Support\Facades\Cache::forget($cacheKey);

        return back();
    }

    public function markAllAsRead(): RedirectResponse
    {
        auth()->user()
            ->notifications()
            ->where('read', false)
            ->update(['read' => true, 'read_at' => now()]);

        // Invalidate the notification counts cache
        $cacheKey = sprintf('notification-counts:%d', auth()->id());
        \Illuminate\Support\Facades\Cache::forget($cacheKey);

        return back();
    }

    public function destroy(Notification $notification): RedirectResponse
    {
        $this->authorize('delete', $notification);
        $notification->delete();

        // Invalidate the notification counts cache
        $cacheKey = sprintf('notification-counts:%d', auth()->id());
        \Illuminate\Support\Facades\Cache::forget($cacheKey);

        return back();
    }

    public function deleteAll(): RedirectResponse
    {
        // Only delete notifications belonging to the authenticated user
        auth()->user()
            ->notifications()
            ->delete();

        // Invalidate the notification counts cache
        $cacheKey = sprintf('notification-counts:%d', auth()->id());
        \Illuminate\Support\Facades\Cache::forget($cacheKey);

        return back()->with('success', 'All notifications have been deleted.');
    }
}
