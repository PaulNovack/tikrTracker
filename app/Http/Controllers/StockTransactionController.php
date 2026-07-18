<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockTransactionRequest;
use App\Http\Requests\UpdateStockTransactionRequest;
use App\Models\StockTransaction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StockTransactionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $transactions = auth()->user()
            ->stockTransactions()
            ->with(['stockBuy', 'sales'])
            ->orderBy('transaction_date', 'desc')
            ->get();

        // Get available buy transactions for selling
        $availableBuys = auth()->user()
            ->stockTransactions()
            ->where('type', 'buy')
            ->get()
            ->filter(function ($buy) {
                return floatval($buy->remaining_quantity) > 0;
            })
            ->map(function ($buy) {
                return [
                    'id' => $buy->id,
                    'symbol' => $buy->symbol,
                    'quantity' => $buy->quantity,
                    'remaining_quantity' => $buy->remaining_quantity,
                    'price_per_share' => $buy->price_per_share,
                    'transaction_date' => $buy->transaction_date,
                ];
            })
            ->values();

        return Inertia::render('investments/stock-transactions/index', [
            'transactions' => $transactions,
            'availableBuys' => $availableBuys,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        $availableBuys = auth()->user()
            ->stockTransactions()
            ->where('type', 'buy')
            ->get()
            ->filter(function ($buy) {
                return floatval($buy->remaining_quantity) > 0;
            })
            ->map(function ($buy) {
                return [
                    'id' => $buy->id,
                    'symbol' => $buy->symbol,
                    'quantity' => $buy->quantity,
                    'remaining_quantity' => $buy->remaining_quantity,
                    'price_per_share' => $buy->price_per_share,
                    'transaction_date' => $buy->transaction_date->format('Y-m-d'),
                ];
            })
            ->values();

        return Inertia::render('investments/stock-transactions/create', [
            'availableBuys' => $availableBuys,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreStockTransactionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // Calculate total amount based on type
        if ($validated['type'] === 'sell') {
            $sellPrice = $validated['sell_price_per_share'] ?? $validated['current_price_per_share'] ?? 0;
            $validated['total_amount'] = ($validated['quantity'] * $sellPrice) - ($validated['fee'] ?? 0);

            // Calculate and store realized P/L for sells
            if (isset($validated['stock_buy_id'])) {
                $transaction = new StockTransaction($validated);
                $transaction->user_id = auth()->id();
                $realizedPL = $transaction->calculateRealizedProfitLoss();
                if ($realizedPL !== null) {
                    $validated['realized_profit_loss'] = $realizedPL;
                }
            }
        } else {
            $validated['total_amount'] = ($validated['quantity'] * $validated['price_per_share']) + ($validated['fee'] ?? 0);
        }

        auth()->user()->stockTransactions()->create($validated);

        return redirect()
            ->route('stock-transactions.index')
            ->with('success', 'Stock transaction added successfully!');
    }

    /**
     * Display the specified resource.
     */
    public function show(StockTransaction $stockTransaction): Response
    {
        $this->authorize('view', $stockTransaction);

        return Inertia::render('investments/stock-transactions/show', [
            'transaction' => $stockTransaction,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(StockTransaction $stockTransaction): Response
    {
        $this->authorize('update', $stockTransaction);

        $stockTransaction->load(['stockBuy', 'sales']);

        $availableBuys = auth()->user()
            ->stockTransactions()
            ->where('type', 'buy')
            ->get()
            ->filter(function ($buy) use ($stockTransaction) {
                // Include current linked buy even if sold
                if ($stockTransaction->stock_buy_id === $buy->id) {
                    return true;
                }

                return floatval($buy->remaining_quantity) > 0;
            })
            ->map(function ($buy) {
                return [
                    'id' => $buy->id,
                    'symbol' => $buy->symbol,
                    'quantity' => $buy->quantity,
                    'remaining_quantity' => $buy->remaining_quantity,
                    'price_per_share' => $buy->price_per_share,
                    'transaction_date' => $buy->transaction_date->format('Y-m-d'),
                ];
            })
            ->values();

        return Inertia::render('investments/stock-transactions/edit', [
            'transaction' => $stockTransaction,
            'availableBuys' => $availableBuys,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateStockTransactionRequest $request, StockTransaction $stockTransaction): RedirectResponse
    {
        $this->authorize('update', $stockTransaction);

        $validated = $request->validated();

        // Recalculate total amount based on type
        if ($validated['type'] === 'sell') {
            $sellPrice = $validated['sell_price_per_share'] ?? $validated['current_price_per_share'] ?? 0;
            $validated['total_amount'] = ($validated['quantity'] * $sellPrice) - ($validated['fee'] ?? 0);

            // Recalculate realized P/L for sells
            if (isset($validated['stock_buy_id'])) {
                $tempTransaction = new StockTransaction($validated);
                $tempTransaction->user_id = $stockTransaction->user_id;
                $realizedPL = $tempTransaction->calculateRealizedProfitLoss();
                if ($realizedPL !== null) {
                    $validated['realized_profit_loss'] = $realizedPL;
                }
            }
        } else {
            $validated['total_amount'] = ($validated['quantity'] * $validated['price_per_share']) + ($validated['fee'] ?? 0);
        }

        $stockTransaction->update($validated);

        return redirect()
            ->route('stock-transactions.index')
            ->with('success', 'Stock transaction updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(StockTransaction $stockTransaction): RedirectResponse
    {
        $this->authorize('delete', $stockTransaction);

        $stockTransaction->delete();

        return redirect()
            ->route('stock-transactions.index')
            ->with('success', 'Stock transaction deleted successfully!');
    }
}
