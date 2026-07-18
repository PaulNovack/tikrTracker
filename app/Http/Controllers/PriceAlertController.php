<?php

namespace App\Http\Controllers;

use App\Models\PriceAlert;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PriceAlertController extends Controller
{
    use AuthorizesRequests;

    /**
     * Store a new price alert
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'asset_info_id' => 'required|exists:asset_info,id',
            'base_price' => 'required|numeric|min:0',
            'up_percentage' => 'required|numeric|min:0',
            'down_percentage' => 'required|numeric|min:0',
            'up_enabled' => 'nullable|boolean',
            'down_enabled' => 'nullable|boolean',
        ]);

        $validated['user_id'] = auth()->id();
        $validated['alert_type'] = 'percentage';
        $validated['up_enabled'] = $request->boolean('up_enabled', true);
        $validated['down_enabled'] = $request->boolean('down_enabled', true);

        // Create or update the alert (one per user-asset pair)
        $alert = PriceAlert::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'asset_info_id' => $validated['asset_info_id'],
            ],
            $validated
        );

        // Calculate trigger prices
        $alert->calculateTriggerPrices();
        $alert->save();

        return back()->with('success', 'Price alert created successfully.');
    }

    /**
     * Create price alerts for all available watched assets
     */
    public function storeAll(): RedirectResponse
    {
        $user = auth()->user();

        // Get assets that are watched but don't have price alerts yet
        $watchedAssetIds = $user->watches()->pluck('asset_info_id');
        $alertedAssetIds = $user->priceAlerts()->pluck('asset_info_id');
        $availableAssetIds = $watchedAssetIds->diff($alertedAssetIds);

        if ($availableAssetIds->isEmpty()) {
            return back()->with('info', 'No available assets to create alerts for.');
        }

        $defaultUpPercentage = config('app.watch_default_up_pct', 2.5);
        $defaultDownPercentage = config('app.watch_default_down_pct', 2.5);

        $alertsCreated = 0;

        foreach ($availableAssetIds as $assetId) {
            $watch = $user->watches()->where('asset_info_id', $assetId)->with('asset')->first();
            if (! $watch || ! $watch->asset) {
                continue;
            }

            // Get current price for base price
            $asset = $watch->asset;
            $basePrice = null;

            // Try to get the most recent price
            $latestPrice = $asset->fiveMinutePrices()
                ->latest('ts')
                ->first();

            if (! $latestPrice) {
                $latestPrice = $asset->dailyPrices()
                    ->mondayOnly()
                    ->latest('date')
                    ->first();
            }

            if ($latestPrice) {
                $basePrice = (float) $latestPrice->price;
            } else {
                // Skip if no price data available
                continue;
            }

            $alert = PriceAlert::create([
                'user_id' => $user->id,
                'asset_info_id' => $assetId,
                'base_price' => $basePrice,
                'alert_type' => 'percentage',
                'up_percentage' => $defaultUpPercentage,
                'down_percentage' => $defaultDownPercentage,
                'up_enabled' => true,
                'down_enabled' => true,
            ]);

            // Calculate trigger prices
            $alert->calculateTriggerPrices();
            $alert->save();

            $alertsCreated++;
        }

        if ($alertsCreated > 0) {
            return back()->with('success', "Successfully created {$alertsCreated} price alerts.");
        } else {
            return back()->with('warning', 'No price alerts could be created. Make sure your watched assets have price data.');
        }
    }

    /**
     * Update an existing price alert
     */
    public function update(Request $request, PriceAlert $priceAlert): RedirectResponse
    {
        $this->authorize('update', $priceAlert);

        $validated = $request->validate([
            'base_price' => 'required|numeric|min:0',
            'up_percentage' => 'required|numeric|min:0',
            'down_percentage' => 'required|numeric|min:0',
            'up_enabled' => 'nullable|boolean',
            'down_enabled' => 'nullable|boolean',
        ]);

        $validated['up_enabled'] = $request->boolean('up_enabled', $priceAlert->up_enabled);
        $validated['down_enabled'] = $request->boolean('down_enabled', $priceAlert->down_enabled);

        $priceAlert->update($validated);

        // Recalculate trigger prices
        $priceAlert->calculateTriggerPrices();
        $priceAlert->save();

        return back()->with('success', 'Price alert updated successfully.');
    }

    /**
     * Toggle alert enabled status
     */
    public function toggle(PriceAlert $priceAlert): RedirectResponse
    {
        $this->authorize('update', $priceAlert);

        $priceAlert->update([
            'up_enabled' => ! $priceAlert->up_enabled,
            'down_enabled' => ! $priceAlert->down_enabled,
            'above_triggered' => false,
            'below_triggered' => false,
        ]);

        return back();
    }

    /**
     * Delete a price alert
     */
    public function destroy(PriceAlert $priceAlert): RedirectResponse
    {
        $this->authorize('delete', $priceAlert);
        $priceAlert->delete();

        return back()->with('success', 'Price alert deleted successfully.');
    }

    /**
     * Delete all price alerts for the authenticated user
     */
    public function destroyAll(): RedirectResponse
    {
        $deletedCount = auth()->user()->priceAlerts()->delete();

        $message = $deletedCount === 1
            ? 'Deleted 1 price alert successfully'
            : "Deleted {$deletedCount} price alerts successfully";

        return back()->with('success', $message);
    }
}
