<?php

namespace App\Http\Controllers;

use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WebullTransactionController extends Controller
{
    public function index(Request $request)
    {
        // Get transactions with profit/loss calculations
        $query = StockTransaction::where('user_id', auth()->id())
            ->with(['buyTransaction' => function ($query) {
                $query->select('id', 'symbol', 'price_per_share', 'transaction_date');
            }]);

        // Apply filters
        if ($request->filled('symbol')) {
            $query->where('symbol', 'like', "%{$request->symbol}%");
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('start_date')) {
            $query->where('transaction_date', '>=', $request->start_date.' 00:00:00');
        }

        if ($request->filled('end_date')) {
            $query->where('transaction_date', '<=', $request->end_date.' 23:59:59');
        }

        // Get paginated results
        $transactions = $query->orderBy('transaction_date', 'desc')->paginate(50);

        // Note: profit_loss and profit_loss_percent are now calculated automatically via model accessors

        // Get summary statistics
        $stats = $this->getTransactionStats($request);

        return Inertia::render('WebullTransactions', [
            'transactions' => $transactions,
            'stats' => $stats,
            'filters' => $request->only(['symbol', 'type', 'start_date', 'end_date']),
        ]);
    }

    private function getTransactionStats(Request $request)
    {
        $userId = auth()->id();

        // Get all completed sell transactions with their buy prices - apply same filters as main query
        $sellQuery = StockTransaction::where('user_id', $userId)
            ->where('type', 'sell')
            ->whereNotNull('stock_buy_id')
            ->with('buyTransaction');

        // Apply the same filters as the main transactions query
        if ($request->filled('symbol')) {
            $sellQuery->where('symbol', 'like', "%{$request->symbol}%");
        }

        if ($request->filled('start_date')) {
            $sellQuery->where('transaction_date', '>=', $request->start_date.' 00:00:00');
        }

        if ($request->filled('end_date')) {
            $sellQuery->where('transaction_date', '<=', $request->end_date.' 23:59:59');
        }

        $sellTransactions = $sellQuery->get();

        $totalTrades = $sellTransactions->count();
        $profitableTrades = 0;
        $totalProfit = 0;
        $totalLoss = 0;

        foreach ($sellTransactions as $sell) {
            if ($sell->buyTransaction) {
                $profitLoss = ($sell->price_per_share - $sell->buyTransaction->price_per_share) * $sell->quantity;

                if ($profitLoss > 0) {
                    $profitableTrades++;
                    $totalProfit += $profitLoss;
                } else {
                    $totalLoss += abs($profitLoss);
                }
            }
        }

        return [
            'total_trades' => $totalTrades,
            'profitable_trades' => $profitableTrades,
            'win_rate' => $totalTrades > 0 ? round(($profitableTrades / $totalTrades) * 100, 1) : 0,
            'total_profit' => $totalProfit,
            'total_loss' => $totalLoss,
            'net_profit_loss' => $totalProfit - $totalLoss,
        ];
    }

    public function updateNotes(Request $request, StockTransaction $transaction)
    {
        // Ensure the transaction belongs to the authenticated user
        if ($transaction->user_id !== auth()->id()) {
            abort(403);
        }

        $request->validate([
            'notes' => 'nullable|string|max:65535',
        ]);

        $transaction->update([
            'notes' => $request->notes,
        ]);

        // Return a simple success response for Inertia
        return response('', 200);
    }
}
