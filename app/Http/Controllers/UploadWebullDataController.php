<?php

namespace App\Http\Controllers;

use App\Models\StockTransaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class UploadWebullDataController extends Controller
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('UploadWebullData');
    }

    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:51200', // 50MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid file',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $file = $request->file('file');
            $content = file_get_contents($file->getRealPath());

            // Parse CSV
            $lines = str_getcsv($content, "\n");
            if (empty($lines)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File appears to be empty',
                ], 422);
            }

            // Get header row and validate format
            $headers = str_getcsv(array_shift($lines));
            $requiredHeaders = ['Name', 'Symbol', 'Side', 'Status', 'Filled', 'Total Qty', 'Price', 'Avg Price', 'Time-in-Force', 'Placed Time', 'Filled Time'];

            $missingHeaders = array_diff($requiredHeaders, $headers);
            if (! empty($missingHeaders)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Webull CSV format. Missing headers: '.implode(', ', $missingHeaders),
                ], 422);
            }

            $processedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;
            $errors = [];

            DB::transaction(function () use ($lines, $headers, &$processedCount, &$skippedCount, &$errorCount, &$errors) {
                foreach ($lines as $lineNumber => $line) {
                    if (empty(trim($line))) {
                        continue;
                    }

                    try {
                        $data = array_combine($headers, str_getcsv($line));

                        // Skip if not filled (cancelled/failed orders)
                        if ($data['Status'] !== 'Filled' || empty($data['Filled Time'])) {
                            $skippedCount++;

                            continue;
                        }

                        // Parse and validate data
                        $parsedData = $this->parseWebullRow($data);

                        // Check for existing transaction by broker_order_id (unique fingerprint)
                        $existingByFingerprint = StockTransaction::where('user_id', auth()->id())
                            ->where('broker_order_id', $parsedData['broker_order_id'])
                            ->exists();

                        if ($existingByFingerprint) {
                            $skippedCount++;

                            continue;
                        }

                        // Legacy check - look for transactions that match key fields but lack broker_order_id
                        $existingLegacy = StockTransaction::where('user_id', auth()->id())
                            ->where('symbol', $parsedData['symbol'])
                            ->where('type', $parsedData['type'])
                            ->where('placed_time', $parsedData['placed_time'])
                            ->where('quantity', $parsedData['quantity'])
                            ->where('price_per_share', $parsedData['price_per_share'])
                            ->whereNull('broker_order_id')
                            ->first();

                        if ($existingLegacy) {
                            // Update legacy record with broker_order_id for future deduplication
                            $existingLegacy->update([
                                'broker_order_id' => $parsedData['broker_order_id'],
                            ]);

                            $skippedCount++;

                            continue;
                        }

                        // Try to create - if duplicate constraint violation occurs, catch and skip
                        try {
                            auth()->user()->stockTransactions()->create($parsedData);
                            $processedCount++;
                        } catch (\Illuminate\Database\QueryException $e) {
                            // Check if it's a duplicate key error
                            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                                $skippedCount++;
                            } else {
                                throw $e; // Re-throw if it's a different error
                            }
                        }

                    } catch (\Exception $e) {
                        $errorCount++;
                        $errors[] = 'Line '.($lineNumber + 2).': '.$e->getMessage();
                        Log::error('Error processing Webull CSV line', [
                            'line' => $lineNumber + 2,
                            'error' => $e->getMessage(),
                            'data' => $data ?? null,
                        ]);
                    }
                }
            });

            // After processing all transactions, link sell orders to their corresponding buy orders
            $this->linkSellOrdersToBuys();

            return response()->json([
                'success' => true,
                'message' => 'Upload completed successfully!',
                'details' => [
                    'processed' => $processedCount,
                    'skipped' => $skippedCount,
                    'errors' => $errorCount,
                    'error_details' => array_slice($errors, 0, 10), // Limit error details
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing Webull CSV upload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing file: '.$e->getMessage(),
            ], 500);
        }
    }

    private function linkSellOrdersToBuys(): void
    {
        // Get all sell transactions that don't have a stock_buy_id set
        $sellTransactions = StockTransaction::where('user_id', auth()->id())
            ->where('type', 'sell')
            ->whereNull('stock_buy_id')
            ->orderBy('placed_time')
            ->get();

        foreach ($sellTransactions as $sellTransaction) {
            // Strategy 1: Find buy transaction with exactly the same placed_time, symbol, and quantity
            $matchedBuy = StockTransaction::where('user_id', auth()->id())
                ->where('type', 'buy')
                ->where('symbol', $sellTransaction->symbol)
                ->where('quantity', $sellTransaction->quantity)
                ->where('placed_time', $sellTransaction->placed_time)
                ->whereNull('stock_buy_id') // Ensure it's a primary buy, not linked to another
                ->first();

            if (! $matchedBuy) {
                // Strategy 2: Find the most recent buy order for the same symbol with matching or larger quantity
                // that occurred before this sell order and hasn't been fully sold
                $availableBuys = StockTransaction::where('user_id', auth()->id())
                    ->where('type', 'buy')
                    ->where('symbol', $sellTransaction->symbol)
                    ->where('transaction_date', '<=', $sellTransaction->transaction_date)
                    ->whereNull('stock_buy_id') // Primary buys only
                    ->orderBy('transaction_date', 'desc')
                    ->get();

                foreach ($availableBuys as $buyCandidate) {
                    // Check if this buy has remaining quantity
                    $totalSold = StockTransaction::where('stock_buy_id', $buyCandidate->id)->sum('quantity');
                    $remainingQuantity = $buyCandidate->quantity - $totalSold;

                    if ($remainingQuantity >= $sellTransaction->quantity) {
                        $matchedBuy = $buyCandidate;
                        break;
                    }
                }
            }

            if ($matchedBuy) {
                $sellTransaction->update(['stock_buy_id' => $matchedBuy->id]);
                Log::info('Linked sell order to buy order', [
                    'sell_id' => $sellTransaction->id,
                    'buy_id' => $matchedBuy->id,
                    'symbol' => $sellTransaction->symbol,
                    'strategy' => $matchedBuy->placed_time == $sellTransaction->placed_time ? 'same_time' : 'fifo',
                ]);
            } else {
                Log::warning('Could not find matching buy order for sell transaction', [
                    'sell_id' => $sellTransaction->id,
                    'symbol' => $sellTransaction->symbol,
                    'quantity' => $sellTransaction->quantity,
                    'transaction_date' => $sellTransaction->transaction_date,
                ]);
            }
        }
    }

    private function parseWebullRow(array $data): array
    {
        // Parse time-in-force
        $timeInForce = strtolower(trim($data['Time-in-Force']));
        $validTimeInForce = ['day', 'gtc', 'ioc', 'fok'];
        if (! in_array($timeInForce, $validTimeInForce)) {
            $timeInForce = 'day'; // Default fallback
        }

        // Parse timestamps
        $placedTime = $this->parseWebullTimestamp($data['Placed Time']);
        $filledTime = $this->parseWebullTimestamp($data['Filled Time']);

        // Use filled time as transaction date for compatibility
        $transactionDate = $filledTime ?: $placedTime;

        // Calculate total amount
        $quantity = (float) str_replace([',', ' '], '', (string) $data['Filled']);
        $pricePerShare = (float) str_replace(['@', ',', '$', ' '], '', (string) $data['Price']);
        $avgPrice = (float) str_replace(['@', ',', '$', ' '], '', (string) $data['Avg Price']);
        $fee = 0; // Webull doesn't show fees in this export
        $side = strtolower(trim($data['Side']));

        if ($side === 'sell') {
            $totalAmount = ($quantity * $avgPrice) - $fee;
        } else {
            $totalAmount = ($quantity * $avgPrice) + $fee;
        }

        $transactionFingerprint = $this->buildWebullFingerprint(
            symbol: strtoupper(trim($data['Symbol'])),
            side: $side,
            placedTime: $placedTime,
            filledTime: $filledTime,
            quantity: $quantity,
            pricePerShare: $pricePerShare,
            avgPrice: $avgPrice,
            timeInForce: $timeInForce,
        );

        return [
            'type' => $side === 'buy' ? 'buy' : 'sell',
            'order_status' => 'filled',
            'time_in_force' => $timeInForce,
            'symbol' => strtoupper(trim($data['Symbol'])),
            'company_name' => trim($data['Name']),
            'quantity' => $quantity,
            'price_per_share' => $pricePerShare,
            'avg_price' => $avgPrice,
            'fee' => $fee,
            'total_amount' => $totalAmount,
            'transaction_date' => $transactionDate,
            'placed_time' => $placedTime,
            'filled_time' => $filledTime,
            'broker_order_id' => $transactionFingerprint,
        ];
    }

    private function buildWebullFingerprint(
        string $symbol,
        string $side,
        ?Carbon $placedTime,
        ?Carbon $filledTime,
        float $quantity,
        float $pricePerShare,
        float $avgPrice,
        string $timeInForce,
    ): string {
        $placedTimeString = $placedTime?->format('Y-m-d H:i:s') ?? '';
        $filledTimeString = $filledTime?->format('Y-m-d H:i:s') ?? '';

        $normalized = implode('|', [
            'webull',
            strtoupper(trim($symbol)),
            strtolower(trim($side)),
            $placedTimeString,
            $filledTimeString,
            number_format($quantity, 8, '.', ''),
            number_format($pricePerShare, 6, '.', ''),
            number_format($avgPrice, 6, '.', ''),
            strtolower(trim($timeInForce)),
        ]);

        return 'webull:'.hash('sha256', $normalized);
    }

    private function parseWebullTimestamp(string $timestamp): ?Carbon
    {
        if (empty(trim($timestamp))) {
            return null;
        }

        try {
            // Webull format: "12/04/2025 13:32:04 EST"
            $cleanTimestamp = trim($timestamp);

            // Remove timezone suffix for parsing
            $cleanTimestamp = preg_replace('/\s+(EST|EDT|CST|CDT|MST|MDT|PST|PDT)$/', '', $cleanTimestamp);

            return Carbon::createFromFormat('m/d/Y H:i:s', $cleanTimestamp);
        } catch (\Exception $e) {
            Log::warning('Failed to parse Webull timestamp', [
                'timestamp' => $timestamp,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
