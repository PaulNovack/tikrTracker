<?php

namespace App\Http\Controllers;

use App\Services\Webull\WebullTradingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WebullTradingController extends Controller
{
    public function __construct(
        private readonly WebullTradingService $webull
    ) {}

    public function index()
    {
        try {
            $positions = $this->webull->listAllPositions();
            $todayOrders = $this->webull->listTodayOrders();
            $openOrders = $this->webull->listAllOpenOrders();
        } catch (\Exception $e) {
            $positions = [];
            $todayOrders = [];
            $openOrders = [];
        }

        return Inertia::render('webull-trading/index', [
            'positions' => $positions,
            'todayOrders' => $todayOrders,
            'openOrders' => $openOrders,
        ]);
    }

    public function buyMarket(Request $request)
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:10',
            'qty' => 'required|integer|min:1',
            'stop_loss_price' => 'nullable|numeric|min:0',
        ]);

        try {
            $result = $this->webull->buyWithOptionalStopLoss(
                symbol: strtoupper($validated['symbol']),
                qty: $validated['qty'],
                limitPrice: null,
                stopLossPrice: $validated['stop_loss_price'] ?? null
            );

            return redirect()->route('webull-trading')
                ->with('success', 'Buy order placed successfully!');
        } catch (\Exception $e) {
            return $this->handleOrderError($e, 'buy');
        }
    }

    public function sellAll(Request $request)
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:10',
            'qty' => 'required|integer|min:1',
        ]);

        try {
            $result = $this->webull->sellMarket(
                symbol: strtoupper($validated['symbol']),
                qty: $validated['qty']
            );

            return redirect()->route('webull-trading')
                ->with('success', 'Sell order placed successfully!');
        } catch (\Exception $e) {
            return $this->handleOrderError($e, 'sell');
        }
    }

    public function setStopLoss(Request $request)
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:10',
            'qty' => 'required|integer|min:1',
            'stop_price' => 'required|numeric|min:0',
        ]);

        try {
            $result = $this->webull->placeStopLoss(
                symbol: strtoupper($validated['symbol']),
                qty: $validated['qty'],
                stopPrice: $validated['stop_price']
            );

            return redirect()->route('webull-trading')
                ->with('success', 'Stop loss order placed successfully!');
        } catch (\Exception $e) {
            return $this->handleOrderError($e, 'stop loss');
        }
    }

    private function handleOrderError(\Exception $e, string $orderType): mixed
    {
        $errorMsg = $e->getMessage();

        if (str_contains($errorMsg, '404') && str_contains($errorMsg, 'Route Not Found')) {
            return redirect()->route('webull-trading')
                ->with('error', 'ERROR: Webull OpenAPI trading NOT AVAILABLE for US accounts. Per official SDK docs: "This interface is currently available only to individual brokerage customers in Webull Japan and institutional brokerage clients in Webull Hong Kong. It is not yet available to Webull US brokerage customers." This page is VIEW-ONLY. Place orders via Webull\'s web/mobile app.');
        }

        if (str_contains($errorMsg, '404') && config('webull.mode') === 'DEV') {
            return redirect()->route('webull-trading')
                ->with('error', 'ERROR: Order placement not supported in DEV mode. Set WEBULL_MODE=PROD in .env to place real orders.');
        }

        return redirect()->route('webull-trading')
            ->with('error', "Failed to place {$orderType} order: ".$errorMsg);
    }
}
