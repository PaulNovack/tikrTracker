<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillAlpacaParentOrderIds extends Command
{
    protected $signature = 'alpaca:backfill-parent-order-ids
                            {--dry-run : Preview changes without modifying the database}
                            {--date= : Only process sells on or after this date (Y-m-d)}
                            {--fix-wrong : Also fix sells whose parent buy is from a different day}
                            {--all : Process all sells including those already with parents}';

    protected $description = 'Backfill null OR wrong parent_alpaca_order_id on filled sell orders by matching via FIFO chronological order';

    private int $updated = 0;

    private int $skippedNoBuy = 0;

    private int $skippedMultiBuy = 0;

    private int $skippedAlreadyLinked = 0;

    private int $fixedWrong = 0;

    public function handle(): int
    {
        // --- Phase 1: Backfill null parent IDs (existing logic) ---
        $query = DB::table('alpaca_orders')
            ->where('side', 'sell')
            ->where('status', 'filled')
            ->whereNull('parent_alpaca_order_id')
            ->where('filled_qty', '>', 0)
            ->orderBy('submitted_at');

        if ($date = $this->option('date')) {
            $query->whereDate('submitted_at', '>=', $date);
        }

        $sells = $query->get(['id', 'symbol', 'filled_qty', 'filled_avg_price', 'submitted_at', 'notes']);

        if ($sells->isNotEmpty()) {
            $this->info("Phase 1: Found {$sells->count()} sells with null parent_alpaca_order_id.\n");
            foreach ($sells as $sell) {
                $this->processSell($sell, false);
            }
        } else {
            $this->info("Phase 1: No sells with null parent_alpaca_order_id found.\n");
        }

        // --- Phase 2: Fix wrong parent IDs (buy on different day) ---
        if ($this->option('fix-wrong') || $this->option('all')) {
            $this->newLine();
            $this->info('Phase 2: Checking sells with wrong parent_alpaca_order_id (buy on different day)...');

            $wrongQuery = "
                SELECT so.id, so.symbol, so.filled_qty, so.filled_avg_price AS sell_price,
                       so.submitted_at,
                       CONVERT_TZ(so.submitted_at, '+00:00', 'America/New_York') AS sell_time_est,
                       bo.id AS wrong_buy_id,
                       bo.alpaca_order_id AS wrong_parent_id,
                       CONVERT_TZ(bo.submitted_at, '+00:00', 'America/New_York') AS buy_time_est
                FROM alpaca_orders so
                JOIN alpaca_orders bo ON bo.alpaca_order_id = so.parent_alpaca_order_id AND bo.side = 'buy'
                WHERE so.side = 'sell'
                    AND so.status = 'filled'
                    AND so.filled_qty > 0
                    AND DATE(CONVERT_TZ(so.submitted_at, '+00:00', 'America/New_York')) !=
                        DATE(CONVERT_TZ(bo.submitted_at, '+00:00', 'America/New_York'))
            ";

            if ($date = $this->option('date')) {
                $wrongQuery .= " AND DATE(CONVERT_TZ(so.submitted_at, '+00:00', 'America/New_York')) >= '{$date}'";
            }

            $wrongQuery .= ' ORDER BY so.submitted_at';

            $wrongSells = DB::select($wrongQuery);

            if (! empty($wrongSells)) {
                $this->info('Found '.count($wrongSells)." sells with wrong parent.\n");
                foreach ($wrongSells as $sell) {
                    $this->fixWrongParent($sell);
                }
            } else {
                $this->info('No sells with wrong parent found.');
            }
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Null parent IDs updated:       {$this->updated}");
        $this->info("Wrong parent IDs fixed:        {$this->fixedWrong}");
        $this->warn("Skipped (no buy match):        {$this->skippedNoBuy}");
        $this->warn("Skipped (multi-buy tie):       {$this->skippedMultiBuy}");
        $this->warn("Skipped (already linked):      {$this->skippedAlreadyLinked}");

        if ($this->option('dry-run')) {
            $this->comment('Dry-run mode: no changes were made.');
        }

        return 0;
    }

    /**
     * Fix a sell whose parent_alpaca_order_id points to a buy from a different day.
     * Find the correct same-day buy via submitted_at proximity.
     */
    private function fixWrongParent(object $sell): void
    {
        $sellEst = $sell->sell_time_est ?? $sell->submitted_at;
        $sellDate = substr((string) $sellEst, 0, 10);

        $this->line("Sell #{$sell->id} {$sell->symbol} qty={$sell->filled_qty} @ \${$sell->sell_price} ({$sellEst})");
        $this->line("  Current parent: Buy #{$sell->wrong_buy_id} (wrong: {$sell->buy_time_est})");

        // Find the correct same-day buy via FIFO: chronologically closest buy
        // on the same day that hasn't been fully consumed by other sells.
        $sameDayBuys = DB::table('alpaca_orders')
            ->where('symbol', $sell->symbol)
            ->where('side', 'buy')
            ->where('status', 'filled')
            ->where('filled_qty', '>', 0)
            ->whereRaw("DATE(CONVERT_TZ(submitted_at, '+00:00', 'America/New_York')) = ?", [$sellDate])
            ->where('submitted_at', '<=', $sell->submitted_at)
            ->orderBy('submitted_at')
            ->get(['id', 'alpaca_order_id', 'filled_qty', 'filled_avg_price', 'submitted_at']);

        if ($sameDayBuys->isEmpty()) {
            $this->warn('  ✗ No same-day buy orders found before this sell');
            $this->skippedNoBuy++;

            return;
        }

        // Filter to buys not fully consumed
        $availableBuys = collect($sameDayBuys)->filter(function ($buy) use ($sell) {
            $existingSellQty = DB::table('alpaca_orders')
                ->where('parent_alpaca_order_id', $buy->alpaca_order_id)
                ->where('side', 'sell')
                ->where('status', 'filled')
                ->where('id', '!=', $sell->id)
                ->sum('filled_qty');

            return (float) $existingSellQty < (float) $buy->filled_qty;
        })->values();

        if ($availableBuys->isEmpty()) {
            $this->warn('  ✗ All same-day buys are already consumed by other sells');
            $this->skippedAlreadyLinked++;

            return;
        }

        // Pick the first available buy (chronological FIFO)
        $bestBuy = $availableBuys->first();

        $this->line("  → Re-linking to Buy #{$bestBuy->id} alpaca={$bestBuy->alpaca_order_id} qty={$bestBuy->filled_qty} @ \${$bestBuy->filled_avg_price} ({$bestBuy->submitted_at})");

        if ($this->option('dry-run')) {
            $this->comment("    [DRY RUN] Would set parent_alpaca_order_id = {$bestBuy->alpaca_order_id}");
        } else {
            DB::table('alpaca_orders')
                ->where('id', $sell->id)
                ->update(['parent_alpaca_order_id' => $bestBuy->alpaca_order_id]);
            $this->info('    ✓ Updated');
        }

        $this->fixedWrong++;
    }

    private function processSell(object $sell, bool $isWrong = false): void
    {
        $this->line("Sell #{$sell->id} {$sell->symbol} qty={$sell->filled_qty} @ \${$sell->filled_avg_price} ({$sell->submitted_at})");

        $buys = DB::table('alpaca_orders')
            ->where('symbol', $sell->symbol)
            ->where('side', 'buy')
            ->where('status', 'filled')
            ->where('filled_qty', '>', 0)
            ->where('submitted_at', '<=', $sell->submitted_at)
            ->orderBy('submitted_at', 'desc')
            ->get(['id', 'alpaca_order_id', 'filled_qty', 'filled_avg_price', 'submitted_at']);

        if ($buys->isEmpty()) {
            $this->warn('  ✗ No buy orders found before this sell');
            $this->skippedNoBuy++;

            return;
        }

        $availableBuys = collect($buys)->filter(function ($buy) {
            $existingSellQty = DB::table('alpaca_orders')
                ->where('parent_alpaca_order_id', $buy->alpaca_order_id)
                ->where('side', 'sell')
                ->where('status', 'filled')
                ->sum('filled_qty');

            return (float) $existingSellQty < (float) $buy->filled_qty;
        })->values();

        if ($availableBuys->isEmpty()) {
            $this->warn('  ✗ All matching buys already fully consumed by other sells');
            $this->skippedAlreadyLinked++;

            return;
        }

        $exactMatch = $availableBuys->first(fn ($buy) => (float) $buy->filled_qty === (float) $sell->filled_qty);
        $bestBuy = $exactMatch ?? $availableBuys->first();

        if (! $exactMatch) {
            $sameQtyCount = $availableBuys->filter(fn ($b) => (float) $b->filled_qty === (float) $bestBuy->filled_qty)->count();
            if ($sameQtyCount > 1) {
                $this->warn("  ✗ Multiple buys with same qty ({$bestBuy->filled_qty}) — ambiguous");
                foreach ($availableBuys as $b) {
                    $this->line("    Buy #{$b->id} alpaca={$b->alpaca_order_id} qty={$b->filled_qty} price={$b->filled_avg_price} date={$b->submitted_at}");
                }
                $this->skippedMultiBuy++;

                return;
            }
        }

        $alreadyLinkedSell = DB::table('alpaca_orders')
            ->where('parent_alpaca_order_id', $bestBuy->alpaca_order_id)
            ->where('side', 'sell')
            ->where('filled_qty', '>', 0)
            ->where('id', '!=', $sell->id)
            ->first(['id', 'filled_qty', 'filled_avg_price']);

        $this->line("  → Linking to Buy #{$bestBuy->id} alpaca={$bestBuy->alpaca_order_id} qty={$bestBuy->filled_qty} @ \${$bestBuy->filled_avg_price}");

        if ($alreadyLinkedSell) {
            $this->line("    ⚠ Buy already linked to Sell #{$alreadyLinkedSell->id} (qty={$alreadyLinkedSell->filled_qty}) — linking anyway for cumulative tracking");
        }

        if ($this->option('dry-run')) {
            $this->comment("    [DRY RUN] Would set parent_alpaca_order_id = {$bestBuy->alpaca_order_id}");
        } else {
            DB::table('alpaca_orders')
                ->where('id', $sell->id)
                ->update(['parent_alpaca_order_id' => $bestBuy->alpaca_order_id]);
            $this->info('    ✓ Updated');
        }

        $this->updated++;
    }
}
