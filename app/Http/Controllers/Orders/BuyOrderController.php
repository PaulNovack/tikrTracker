<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\WebullToken;
use App\Services\StockPriceService;
use App\Services\Webull\WebullAuthClient;
use App\Services\Webull\WebullInstrumentClient;
use App\Services\Webull\WebullOrderService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class BuyOrderController extends Controller
{
    public function __construct(
        private readonly StockPriceService $priceService,
        private readonly WebullOrderService $orderService,
        private readonly WebullInstrumentClient $instrumentClient,
        private readonly WebullAuthClient $authClient
    ) {}

    public function index(): Response
    {
        return Inertia::render('Orders/BuyOrder');
    }

    public function calculateShares(Request $request): JsonResponse
    {
        $request->validate([
            'symbol' => 'required|string|max:10',
            'amount' => 'required|numeric|min:0',
        ]);

        $symbol = strtoupper($request->input('symbol'));
        $amount = (float) $request->input('amount');

        $priceData = $this->priceService->getLatestPrice($symbol);

        if (! $priceData) {
            return response()->json([
                'error' => "No recent price data found for {$symbol}",
            ], 404);
        }

        $maxShares = $this->priceService->calculateMaxShares($amount, $priceData['price']);

        return response()->json([
            'symbol' => $symbol,
            'price' => $priceData['price'],
            'timestamp' => $priceData['timestamp'],
            'max_shares' => $maxShares,
            'total_cost' => $maxShares * $priceData['price'],
        ]);
    }

    public function placeOrder(Request $request): JsonResponse
    {
        $request->validate([
            'symbol' => 'required|string|max:10',
            'shares' => 'required|integer|min:1',
        ]);

        $symbol = strtoupper($request->input('symbol'));
        $shares = (int) $request->input('shares');
        $accountId = config('webull.account_id');

        if (! $accountId) {
            return response()->json([
                'error' => 'Webull account ID not configured',
            ], 500);
        }

        try {
            // Ensure we have a valid token before placing order
            $this->ensureValidToken();

            // Place market buy order (v2 API uses symbol directly)
            $orderResponse = $this->orderService->placeMarket(
                accountId: $accountId,
                symbol: $symbol,
                side: 'BUY',
                qty: $shares,
                tif: 'DAY'
            );

            // Log successful order
            Log::info('Webull order placed successfully', [
                'symbol' => $symbol,
                'shares' => $shares,
                'response' => $orderResponse,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Market order placed for {$shares} shares of {$symbol}",
                'order' => $orderResponse,
            ]);

        } catch (\RuntimeException $e) {
            // Webull API error
            Log::error('Webull order failed', [
                'symbol' => $symbol,
                'shares' => $shares,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Order failed: '.$e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            // Unexpected error
            Log::error('Unexpected error placing order', [
                'symbol' => $symbol,
                'shares' => $shares,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // In development, show detailed error; in production, generic message
            $errorMessage = config('app.debug')
                ? 'An unexpected error occurred: '.$e->getMessage()
                : 'An unexpected error occurred. Please try again.';

            return response()->json([
                'error' => $errorMessage,
            ], 500);
        }
    }

    /**
     * Ensure a valid token exists, create one if needed
     */
    private function ensureValidToken(): void
    {
        $mode = strtoupper(config('webull.mode', 'DEV'));

        // Check for existing valid token (NORMAL status and not expired)
        $existingToken = WebullToken::where('environment', $mode)
            ->where('status', 'NORMAL')
            ->where('expires_at', '>', now())
            ->first();

        if ($existingToken) {
            return; // Valid token exists, nothing to do
        }

        // Check for ANY token (expired, invalid, pending, etc.) and clean it up
        $anyToken = WebullToken::where('environment', $mode)->first();

        if ($anyToken) {
            if ($anyToken->isExpired()) {
                Log::info("Token expired for {$mode} environment, deleting and creating new token", [
                    'old_token_status' => $anyToken->status,
                    'expired_at' => $anyToken->expires_at->toDateTimeString(),
                ]);
                $anyToken->delete();
            } elseif ($anyToken->status === 'INVALID') {
                Log::info("Invalid token found for {$mode} environment, deleting and creating new token");
                $anyToken->delete();
            } elseif ($anyToken->status === 'PENDING') {
                $expiresIn = now()->diffInMinutes($anyToken->expires_at);

                if ($mode === 'DEV') {
                    throw new \RuntimeException(
                        'UAT token requires SMS verification but UAT accounts cannot verify via mobile app. '.
                        "PENDING tokens expire in {$expiresIn} minutes and cannot be used for orders. ".
                        'Contact Webull API support to request a pre-verified UAT token or bypass SMS verification for your test account.'
                    );
                } else {
                    throw new \RuntimeException(
                        'A Webull token exists but requires SMS verification. '.
                        'Please open your Webull mobile app and approve the login request. '.
                        "Token expires in {$expiresIn} minutes."
                    );
                }
            }
        }

        // No valid token found, create a new one
        Log::info("No valid Webull token found for {$mode} environment, creating new token...");

        try {
            $response = $this->authClient->createToken();

            // Log the full response for debugging
            Log::info('Token creation response', ['response' => $response]);

            // Validate response structure
            if (! isset($response['token'])) {
                Log::error('Token creation response missing token', ['response' => $response]);
                throw new \RuntimeException(
                    'Invalid token response from Webull API. Response: '.json_encode($response)
                );
            }

            // Save token to database
            WebullToken::updateOrCreate(
                ['environment' => $mode],
                [
                    'token' => $response['token'],
                    'status' => $response['status'] ?? 'PENDING',
                    'expires_at' => Carbon::createFromTimestampMs($response['expires']),
                ]
            );

            $status = $response['status'] ?? 'PENDING';

            if ($status === 'PENDING') {
                $expiresAt = Carbon::createFromTimestampMs($response['expires']);
                $expiresIn = now()->diffInMinutes($expiresAt);
                Log::warning("Token created but requires SMS verification. Status: {$status}, Expires in {$expiresIn} minutes");

                if ($mode === 'DEV') {
                    throw new \RuntimeException(
                        "UAT token created but requires SMS verification (expires in {$expiresIn} minutes). ".
                        'UAT test accounts cannot verify via mobile app. PENDING tokens cannot be used for orders. '.
                        'Please contact Webull API support to request a pre-verified UAT token.'
                    );
                } else {
                    throw new \RuntimeException(
                        'Token created successfully but requires SMS verification. '.
                        'Please open your Webull mobile app and approve the login request. '.
                        "Token expires in {$expiresIn} minutes."
                    );
                }
            }

            if ($status !== 'NORMAL') {
                Log::warning("Token created with unexpected status: {$status}");
                throw new \RuntimeException("Token status is '{$status}'. Expected 'NORMAL'. Please check your Webull account.");
            }

            Log::info("Webull token created successfully for {$mode} environment");

        } catch (\RuntimeException $e) {
            // Re-throw Webull API errors with helpful context
            throw new \RuntimeException('Failed to create Webull token: '.$e->getMessage(), 0, $e);
        }
    }
}
