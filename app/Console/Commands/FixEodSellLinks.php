<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixEodSellLinks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alpaca:fix-eod-sell-links
                            {--date= : Date to fix (default: yesterday, YYYY-MM-DD)}
                            {--dry-run : Show what would be fixed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix buy/sell linking for a date. For dates without EOD sell DB records, fetches closed sells from Alpaca API and creates proper per-buy sell links.';

    public function __construct(
        private AlpacaPythonService $alpacaService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->option('date') ?? now('America/New_York')->subDay()->toDateString();
        $isDryRun = $this->option('dry-run');

        $this->info("Fixing sell links for {$date}...");

        // Find all filled buy orders for this date that have NO linked sell
        $unlinkedBuys = $this->getUnlinkedBuys($date);

        if ($unlinkedBuys->isEmpty()) {
            $this->info("All buys for {$date} are already linked to sells ✓");

            return 0;
        }

        $symbols = $unlinkedBuys->pluck('symbol')->unique()->values();
        $this->info('Found '.$unlinkedBuys->count().' unlinked buy(s) across '.$symbols->count().' symbol(s)');

        // Fetch closed (filled) sell orders from Alpaca API
        $this->info('Fetching closed orders from Alpaca API...');
        $closedResult = $this->alpacaService->getOrders(
            status: 'closed',
            limit: 500,
            startDate: $date,
            endDate: $date,
        );

        if (! $closedResult['success']) {
            $this->error('Failed to fetch closed orders from Alpaca: '.($closedResult['error'] ?? 'unknown error'));

            return 1;
        }

        $closedData = json_decode($closedResult['output'], true);
        $closedOrders = $closedData['orders'] ?? [];

        $this->info('Fetched '.count($closedOrders).' closed orders from Alpaca');

        // Index closed sells by symbol: only filled market/day sells (likely EOD liquidations)
        $closedSells = [];
        foreach ($closedOrders as $co) {
            if (
                strtolower($co['side'] ?? '') === 'sell'
                && ($co['status'] ?? '') === 'filled'
                && (float) ($co['filled_qty'] ?? 0) > 0
                && in_array($co['order_type'] ?? '', ['market', 'day'], true)
            ) {
                $sym = strtoupper($co['symbol'] ?? '');
                if (! isset($closedSells[$sym])) {
                    $closedSells[$sym] = $co;
                }
            }
        }

        // Also look for existing EOD sell DB records
        $eodSells = AlpacaOrder::where('notes', 'like', '%End of day sell%')
            ->whereDate('created_at', $date)
            ->where('side', 'sell')
            ->get()
            ->keyBy('symbol');

        $fixed = 0;

        // Group unlinked buys by symbol (FIFO order)
        $unlinkedBySymbol = $unlinkedBuys->groupBy('symbol');

        foreach ($unlinkedBySymbol as $symbol => $buys) {
            // First try existing DB EOD sell, then fall back to Alpaca closed sell
            $sellRecord = $eodSells->get($symbol);
            $sellAlpacaId = null;
            $sellPrice = null;
            $sellQty = null;

            if ($sellRecord) {
                $sellAlpacaId = $sellRecord->alpaca_order_id;
                $sellPrice = (float) $sellRecord->filled_avg_price;
                $sellQty = (float) $sellRecord->filled_qty;
            } elseif (isset($closedSells[$symbol])) {
                $co = $closedSells[$symbol];
                $sellAlpacaId = $co['id'] ?? ('reconciled-'.$symbol.'-'.$date);
                $sellPrice = (float) ($co['filled_avg_price'] ?? 0);
                $sellQty = (float) ($co['filled_qty'] ?? 0);

                // If Alpaca returned a real ID, use it; otherwise fall back to the reconciled- prefix.
                if (isset($co['id'])) {
                    $sellAlpacaId = $co['id'];
                } else {
                    $sellAlpacaId = 'reconciled-'.$symbol.'-'.$date;
                }

                // Remove this sell from the pool so another symbol doesn't reuse it
                unset($closedSells[$symbol]);
            } else {
                $this->warn("  {$symbol}: No Alpaca closed sell found — skipped");

                continue;
            }

            $remaining = $sellQty;

            foreach ($buys as $buy) {
                $buyQty = (float) $buy->filled_qty;

                if ($remaining <= 0.01) {
                    break;
                }

                $matchedQty = min($remaining, $buyQty);

                if ($isDryRun) {
                    $this->info("  [DRY RUN] {$symbol}: Would link {$matchedQty}/{$buyQty} shares to buy {$buy->alpaca_order_id}");
                } else {
                    AlpacaOrder::create([
                        'alpaca_order_id' => $sellAlpacaId,
                        'client_order_id' => null,
                        'is_paper' => (bool) config('alpaca.paper_trading', true),
                        'symbol' => $symbol,
                        'side' => 'sell',
                        'qty' => $matchedQty,
                        'filled_qty' => $matchedQty,
                        'filled_avg_price' => $sellPrice,
                        'order_type' => 'market',
                        'status' => 'filled',
                        'time_in_force' => 'day',
                        'submitted_at' => $date.' 15:45:00',
                        'filled_at' => $date.' 15:45:00',
                        'parent_alpaca_order_id' => $buy->alpaca_order_id,
                        'notes' => 'End of day sell - automated (retroactive fix)',
                        'atr' => $buy->atr,
                        'atr_pct' => $buy->atr_pct,
                    ]);

                    $this->info("  {$symbol}: Linked {$matchedQty}/{$buyQty} shares to buy {$buy->alpaca_order_id}");
                }

                $remaining -= $matchedQty;
                $fixed++;
            }
        }

        if ($isDryRun) {
            $this->newLine();
            $this->info('DRY RUN — no changes made. Run without --dry-run to apply.');
        } else {
            $this->newLine();
            $this->info("Fixed {$fixed} unlinked buy order(s) for {$date}");
        }

        return 0;
    }

    /**
     * Find buys that need sell-link creation. A symbol needs linking only when
     * its total buy quantity exceeds its total sell quantity for the date.
     * Checking per-order linkage alone can produce false positives when a
     * single sell (e.g. stop loss) liquidates multiple buys — the sell is
     * linked to only one buy via parent_alpaca_order_id, but its filled_qty
     * covers all of them.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getUnlinkedBuys(string $date)
    {
        $allBuys = AlpacaOrder::whereDate('created_at', $date)
            ->where('side', 'buy')
            ->where('status', 'filled')
            ->where('filled_qty', '>', 0)
            ->orderBy('filled_at', 'asc')
            ->get();

        if ($allBuys->isEmpty()) {
            return $allBuys;
        }

        // Group by symbol and compute net position (total buy - total sell)
        $symbols = $allBuys->pluck('symbol')->unique()->values()->toArray();

        $sellTotals = AlpacaOrder::whereIn('symbol', $symbols)
            ->whereDate('created_at', $date)
            ->where('side', 'sell')
            ->where('status', 'filled')
            ->where('filled_qty', '>', 0)
            ->groupBy('symbol')
            ->selectRaw('symbol, SUM(filled_qty) as total_sell_qty')
            ->pluck('total_sell_qty', 'symbol');

        // Only unlinked symbols: those where total buys > total sells
        $unlinkedSymbols = [];
        foreach ($allBuys->groupBy('symbol') as $symbol => $buys) {
            $totalBuyQty = $buys->sum('filled_qty');
            $totalSellQty = (float) ($sellTotals->get($symbol) ?? 0);
            if ($totalBuyQty > $totalSellQty + 0.01) {
                $unlinkedSymbols[] = $symbol;
            }
        }

        if (empty($unlinkedSymbols)) {
            return collect();
        }

        return $allBuys->filter(fn (AlpacaOrder $buy) => in_array($buy->symbol, $unlinkedSymbols, true))->values();
    }
}
