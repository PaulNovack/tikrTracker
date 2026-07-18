<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use Illuminate\Console\Command;

class BackfillAlpacaOrderFilledAt extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alpaca:backfill-filled-at';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill filled_at timestamps for filled Alpaca orders';

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
        $this->info('Backfilling filled_at timestamps for filled Alpaca orders...');

        // Get all filled orders without filled_at timestamp
        $orders = AlpacaOrder::where('status', 'filled')
            ->whereNull('filled_at')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No orders need backfilling.');

            return Command::SUCCESS;
        }

        $this->info("Found {$orders->count()} orders to backfill.");

        $updated = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                // First try to get the filled_at from Alpaca API
                $result = $this->alpacaService->checkOrderStatus($order->alpaca_order_id);

                if ($result['success']) {
                    $statusData = $this->parseStatusResponse($result['output']);

                    if ($statusData && isset($statusData['order']['filled_at'])) {
                        $filledAt = now()->parse($statusData['order']['filled_at']);
                    } else {
                        // Fallback to created_at if API doesn't have filled_at
                        $filledAt = $order->created_at;
                    }
                } else {
                    // Fallback to created_at if API call fails
                    $filledAt = $order->created_at;
                }

                $order->update([
                    'filled_at' => $filledAt,
                    'paper' => (bool) config('alpaca.paper_trading', true),
                ]);
                $updated++;

                $this->line("Updated {$order->symbol} ({$order->alpaca_order_id}): {$filledAt}");

                // Rate limiting to avoid overwhelming API
                usleep(100000); // 0.1 second delay
            } catch (\Exception $e) {
                $failed++;
                $this->error("Failed to update {$order->symbol} ({$order->alpaca_order_id}): {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info('Backfill complete!');
        $this->info("Updated: {$updated}");
        if ($failed > 0) {
            $this->warn("Failed: {$failed}");
        }

        return Command::SUCCESS;
    }

    protected function parseStatusResponse(string $output): ?array
    {
        // Try to extract JSON from output
        if (preg_match('/(\{.*\})/s', $output, $matches)) {
            $json = json_decode($matches[1], true);
            if ($json) {
                return $json;
            }
        }

        return null;
    }
}
