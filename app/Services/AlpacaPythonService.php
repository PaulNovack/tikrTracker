<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class AlpacaPythonService
{
    protected string $pythonPath;

    protected string $scriptsPath;

    public function __construct()
    {
        $this->pythonPath = config('services.python.path', 'python3');
        $this->scriptsPath = base_path('alpaca_python_api');
    }

    /**
     * Place an order using place_order.py
     *
     * @param  string  $symbol  Stock symbol
     * @param  float  $qty  Quantity to buy/sell
     * @param  string  $side  'buy' or 'sell'
     * @param  float|null  $stopPrice  Stop loss price
     * @param  float|null  $takeProfitPrice  Take profit price (required with stopPrice)
     * @param  float|null  $stopLimit  Stop limit price
     * @param  bool  $stopOnly  Stop-only order (no entry)
     * @param  bool  $fractional  Allow fractional shares (default: false, whole shares only)
     * @param  float|null  $limitPrice  Entry limit price. If set, submits a LIMIT order instead of MARKET.
     * @return array{success: bool, output: string, error: string|null}
     */
    public function placeOrder(
        string $symbol,
        float $qty,
        string $side,
        ?float $stopPrice = null,
        ?float $takeProfitPrice = null,
        ?float $stopLimit = null,
        bool $stopOnly = false,
        bool $fractional = false,
        ?float $limitPrice = null
    ): array {
        $args = [
            '--symbol', $symbol,
            '--qty', $qty,
            '--side', $side,
        ];

        if ($stopPrice !== null) {
            $args[] = '--stop-price';
            $args[] = round($stopPrice, 2);
        }

        if ($takeProfitPrice !== null) {
            $args[] = '--take-profit';
            $args[] = round($takeProfitPrice, 2);
        }

        if ($stopLimit !== null) {
            $args[] = '--stop-limit';
            $args[] = round($stopLimit, 2);
        }

        if ($stopOnly) {
            $args[] = '--stop-only';
        }

        if ($fractional) {
            $args[] = '--fractional';
        }

        if ($limitPrice !== null) {
            $args[] = '--limit-price';
            $args[] = round($limitPrice, 2);
        }

        return $this->runScript('place_order.py', $args);
    }

    /**
     * Get account details
     *
     * @return array{success: bool, output: string, error: string|null}
     */
    public function getAccountDetails(): array
    {
        return $this->runScript('account_details.py');
    }

    /**
     * Check order status by Alpaca order ID
     *
     * @param  string  $alpacaOrderId  Alpaca order ID
     * @return array{success: bool, output: string, error: string|null}
     */
    public function checkOrderStatus(string $alpacaOrderId): array
    {
        $args = [
            '--order-id', $alpacaOrderId,
        ];

        return $this->runScript('check_order_status.py', $args);
    }

    /**
     * Get all orders from Alpaca API
     *
     * @param  string|null  $status  Filter by status: 'open', 'closed', 'all'
     * @param  int  $limit  Maximum number of orders to return
     * @param  string|null  $startDate  Filter orders after this date (YYYY-MM-DD)
     * @param  string|null  $endDate  Filter orders before this date (YYYY-MM-DD)
     * @return array{success: bool, output: string, error: string|null}
     */
    public function getOrders(?string $status = null, int $limit = 500, ?string $startDate = null, ?string $endDate = null): array
    {
        $args = [
            '--limit', (string) $limit,
        ];

        if ($status) {
            $args[] = '--status';
            $args[] = $status;
        }

        if ($startDate) {
            $args[] = '--start-date';
            $args[] = $startDate;
        }

        if ($endDate) {
            $args[] = '--end-date';
            $args[] = $endDate;
        }

        return $this->runScript('get_orders.py', $args);
    }

    /**
     * Cancel a specific order by its Alpaca order UUID
     *
     * @param  string  $alpacaOrderId  Alpaca order UUID
     * @return array{success: bool, output: string, error: string|null}
     */
    public function cancelOrderById(string $alpacaOrderId): array
    {
        $args = [
            '--order-id', $alpacaOrderId,
        ];

        return $this->runScript('cancel_order.py', $args);
    }

    /**
     * Cancel all orders for a specific symbol
     *
     * @param  string  $symbol  Stock symbol
     * @return array{success: bool, output: string, error: string|null}
     */
    public function cancelOrdersBySymbol(string $symbol): array
    {
        $args = [
            '--symbol', $symbol,
        ];

        return $this->runScript('cancel_all_orders.py', $args);
    }

    /**
     * Check position for a specific symbol
     *
     * @param  string  $symbol  Stock symbol
     * @return array{success: bool, output: string, error: string|null}
     */
    public function checkPosition(string $symbol): array
    {
        $args = [
            '--symbol', $symbol,
        ];

        return $this->runScript('check_position.py', $args);
    }

    /**
     * Run a Python script from the alpaca_python_api directory
     *
     * @param  string  $scriptName  Name of the Python script
     * @param  array  $args  Additional command line arguments
     * @return array{success: bool, output: string, error: string|null}
     */
    public function runScript(string $scriptName, array $args = []): array
    {
        $scriptPath = $this->scriptsPath.'/'.$scriptName;

        if (! file_exists($scriptPath)) {
            Log::error("Python script not found: {$scriptPath}");

            return [
                'success' => false,
                'output' => '',
                'error' => "Script not found: {$scriptName}",
            ];
        }

        $command = array_merge(
            [$this->pythonPath, $scriptPath],
            $args
        );

        try {
            $result = Process::path($this->scriptsPath)
                ->run($command);

            $success = $result->successful();
            $output = $result->output();
            $error = $result->errorOutput();

            if (! $success) {
                $context = [
                    'command' => implode(' ', $command),
                    'error' => $error,
                    'output' => $output,
                ];

                if ($this->isExpectedOrderNotFound($scriptName, $error)) {
                    Log::warning("Python script returned expected order-not-found: {$scriptName}", $context);
                } elseif ($this->isInsufficientQtyError($scriptName, $error)) {
                    Log::warning("Python script place_order skipped — position already held by open order: {$scriptName}", [
                        'symbol' => $this->extractSymbolFromArgs($args),
                        'reason' => 'insufficient_qty_available',
                    ]);
                } elseif ($this->isStopPriceError($scriptName, $error)) {
                    $reason = 'stop_price >= market price';
                    if (preg_match('/"market_price"\s*:\s*"?([\d.]+)"?/i', $error, $m)) {
                        $reason = 'stop ($'.round((float) $this->extractStopPriceFromArgs($args), 2).') >= market ($'.$m[1].')';
                    }
                    Log::warning("Python script place_order failed ({$reason}) — retry will adjust stop: {$scriptName}", [
                        'command' => implode(' ', $command),
                    ]);
                } else {
                    Log::error("Python script failed: {$scriptName}", $context);
                }
            }

            return [
                'success' => $success,
                'output' => $output,
                'error' => $error ?: null,
            ];
        } catch (\Exception $e) {
            Log::error("Exception running Python script: {$scriptName}", [
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'output' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute a custom Python script with custom arguments
     *
     * @param  string  $scriptName  Name of the Python script
     * @param  array  $args  Command line arguments
     * @return array{success: bool, output: string, error: string|null}
     */
    public function executeScript(string $scriptName, array $args = []): array
    {
        return $this->runScript($scriptName, $args);
    }

    protected function isExpectedOrderNotFound(string $scriptName, string $error): bool
    {
        if ($scriptName !== 'check_order_status.py') {
            return false;
        }

        $lowerError = strtolower($error);

        return str_contains($error, '40410000') || str_contains($lowerError, 'order not found');
    }

    /**
     * Check if the error is a stop price >= market price (retry handles this gracefully).
     */
    protected function isStopPriceError(string $scriptName, string $error): bool
    {
        return str_contains($scriptName, 'place_order.py')
            && str_contains($error, 'stop price must be less than current price');
    }

    /**
     * Check if the error is a wash trade rejection (opposite side order exists).
     */
    public static function isWashTradeError(string $error): bool
    {
        return str_contains($error, '40310000')
            || str_contains($error, 'opposite side limit order exists')
            || str_contains($error, 'potential wash trade detected');
    }

    /**
     * Extract the market price from a stop-order rejection error message.
     * Alpaca includes a JSON payload with "market_price" when a stop order
     * is rejected for being at or above the current trading price.
     *
     * Also tries to parse the price from plain-text error formats.
     *
     * @return float|null The market price, or null if not found
     */
    public static function extractMarketPriceFromError(string $error): ?float
    {
        // Try JSON payload: {"market_price": "12.87"}
        if (preg_match('/"market_price"\s*:\s*"?([\d.]+)"?/i', $error, $matches)) {
            return (float) $matches[1];
        }

        // Try "stop price must be less than current price of $12.87"
        if (preg_match('/current price of \$?([\d.]+)/i', $error, $matches)) {
            return (float) $matches[1];
        }

        // Try "current price ([0-9.]+)"
        if (preg_match('/current price[:\s]+\$?([\d.]+)/i', $error, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    /**
     * Check if the error is an "insufficient qty" response — the position is already
     * tied up in another open order (e.g. a duplicate stop-loss) or was already sold.
     * This is a normal operational occurrence, not a system error.
     */
    protected function isInsufficientQtyError(string $scriptName, string $error): bool
    {
        return str_contains($scriptName, 'place_order.py')
            && str_contains($error, 'insufficient qty available');
    }

    /**
     * Extract the symbol from the command-line args array.
     *
     * @param  array<int, string>  $args
     */
    protected function extractSymbolFromArgs(array $args): string
    {
        $symbolIndex = array_search('--symbol', $args, true);

        return ($symbolIndex !== false && isset($args[$symbolIndex + 1]))
            ? (string) $args[$symbolIndex + 1]
            : 'unknown';
    }

    /**
     * Extract the stop price from the command-line args array.
     *
     * @param  array<int, string>  $args
     */
    protected function extractStopPriceFromArgs(array $args): float
    {
        $stopIndex = array_search('--stop-price', $args, true);

        return ($stopIndex !== false && isset($args[$stopIndex + 1]))
            ? (float) $args[$stopIndex + 1]
            : 0.0;
    }

    /**
     * Get list of symbols bought on a specific date and total dollar amount in open positions
     *
     * This method finds all buy orders filled on the given date and checks which ones
     * are still open (not fully sold or stopped out). It calculates the remaining quantity
     * and the dollar amount invested in those open positions.
     *
     * @param  string|null  $date  Date to check (YYYY-MM-DD), defaults to today
     * @return array{date: string, symbols: array<string>, total_invested: float, positions: array}
     *
     * @example
     * $service = app(\App\Services\AlpacaPythonService::class);
     *
     * // Get today's open positions
     * $today = $service->getOpenPositionsForDate();
     * // Returns: ['date' => '2026-01-29', 'symbols' => ['AAPL', 'MSFT'], 'total_invested' => 10000.00, 'positions' => [...]]
     *
     * // Get specific date's open positions
     * $yesterday = $service->getOpenPositionsForDate('2026-01-28');
     *
     * // Access the data
     * echo "Total invested: $" . $today['total_invested'];
     * echo "Open symbols: " . implode(', ', $today['symbols']);
     * foreach ($today['positions'] as $position) {
     *     echo "{$position['symbol']}: {$position['qty']} shares @ ${$position['avg_price']}";
     * }
     */
    public function getOpenPositionsForDate(?string $date = null): array
    {
        $date = $date ?? now()->format('Y-m-d');

        // Get all buy orders filled on this date
        $buyOrders = \App\Models\AlpacaOrder::query()
            ->where('side', 'buy')
            ->where('status', 'filled')
            ->whereNotNull('filled_at')
            ->whereDate('filled_at', $date)
            ->get();

        $openPositions = [];
        $totalInvested = 0;

        foreach ($buyOrders as $buyOrder) {
            // Check if this position has been fully closed (sold or stopped out)
            $sellOrders = \App\Models\AlpacaOrder::query()
                ->where('symbol', $buyOrder->symbol)
                ->where('side', 'sell')
                ->where('status', 'filled')
                ->whereNotNull('filled_at')
                ->where('filled_at', '>=', $buyOrder->filled_at)
                ->sum('filled_qty');

            $remainingQty = (float) $buyOrder->filled_qty - (float) $sellOrders;

            // If there's still quantity remaining, this position is open
            if ($remainingQty > 0) {
                $positionValue = $remainingQty * (float) $buyOrder->filled_avg_price;

                $openPositions[] = [
                    'symbol' => $buyOrder->symbol,
                    'buy_order_id' => $buyOrder->alpaca_order_id,
                    'filled_at' => $buyOrder->filled_at->format('Y-m-d H:i:s'),
                    'qty' => $remainingQty,
                    'avg_price' => (float) $buyOrder->filled_avg_price,
                    'invested' => $positionValue,
                ];

                $totalInvested += $positionValue;
            }
        }

        return [
            'date' => $date,
            'symbols' => array_unique(array_column($openPositions, 'symbol')),
            'total_invested' => round($totalInvested, 2),
            'positions' => $openPositions,
        ];
    }

    /**
     * Get Alpaca account information including buying power and cash balance
     *
     * @return array{success: bool, buying_power: float|null, cash: float|null, portfolio_value: float|null, equity: float|null, error: string|null}
     */
    public function getAccountInfo(): array
    {
        $scriptPath = $this->scriptsPath.'/account_details.py';

        if (! file_exists($scriptPath)) {
            Log::error('AlpacaPythonService: account_details.py not found', ['path' => $scriptPath]);

            return [
                'success' => false,
                'buying_power' => null,
                'cash' => null,
                'portfolio_value' => null,
                'equity' => null,
                'error' => 'account_details.py script not found',
            ];
        }

        $result = Process::timeout(10)->run([
            $this->pythonPath,
            $scriptPath,
        ]);

        if ($result->failed()) {
            Log::error('AlpacaPythonService: Failed to get account info', [
                'error' => $result->errorOutput(),
            ]);

            return [
                'success' => false,
                'buying_power' => null,
                'cash' => null,
                'portfolio_value' => null,
                'equity' => null,
                'error' => $result->errorOutput(),
            ];
        }

        $output = trim($result->output());

        try {
            $accountData = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            return [
                'success' => true,
                'buying_power' => isset($accountData['buying_power']) ? (float) $accountData['buying_power'] : null,
                'cash' => isset($accountData['cash']) ? (float) $accountData['cash'] : null,
                'portfolio_value' => isset($accountData['portfolio_value']) ? (float) $accountData['portfolio_value'] : null,
                'equity' => isset($accountData['equity']) ? (float) $accountData['equity'] : null,
                'error' => null,
            ];
        } catch (\JsonException $e) {
            Log::error('AlpacaPythonService: Failed to parse account info JSON', [
                'output' => $output,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'buying_power' => null,
                'cash' => null,
                'portfolio_value' => null,
                'equity' => null,
                'error' => 'Failed to parse account data: '.$e->getMessage(),
            ];
        }
    }
}
