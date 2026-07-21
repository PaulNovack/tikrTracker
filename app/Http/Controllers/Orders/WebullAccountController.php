<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\WebullToken;
use App\Services\Webull\WebullAccountClient;
use App\Services\Webull\WebullAuthClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class WebullAccountController extends Controller
{
    public function __construct(
        private readonly WebullAccountClient $accountClient,
        private readonly WebullAuthClient $authClient
    ) {}

    public function index(): Response
    {
        return Inertia::render('Orders/WebullAccount');
    }

    public function getAccountId(Request $request)
    {
        try {
            Log::info('Fetching Webull account list');

            // Fetch accounts directly - no token needed for Individual API
            // Authentication is done via signature in the request headers
            $accounts = $this->accountClient->listAccounts();

            if (empty($accounts)) {
                return response()->json([
                    'success' => false,
                    'error' => 'No accounts found',
                    'raw_response' => [],
                ], 404);
            }

            // Get the first account ID
            $accountId = $accounts[0]['account_id'] ?? null;

            Log::info('Webull account fetched', [
                'account_id' => $accountId,
                'total_accounts' => count($accounts),
            ]);

            return response()->json([
                'success' => true,
                'account_id' => $accountId,
                'total_accounts' => count($accounts),
                'raw_response' => $accounts,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch Webull account', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'raw_response' => null,
            ], 500);
        }
    }

    public function createToken(Request $request)
    {
        try {
            Log::info('Creating Webull access token');

            // For Individual API, token creation uses username/password
            // The authentication is handled via app_key/app_secret in the signature
            // Pass null to use credentials from config
            $response = $this->authClient->createToken();

            Log::info('Webull token created', [
                'response' => $response,
            ]);

            // Store token in database
            $mode = strtoupper(config('webull.mode', 'DEV'));
            $expiresAt = isset($response['expires'])
                ? \Carbon\Carbon::createFromTimestampMs($response['expires'])
                : now()->addDays(15); // Default 15 days if not provided

            WebullToken::updateOrCreate(
                ['environment' => $mode],
                [
                    'token' => $response['token'] ?? null,
                    'status' => $response['status'] ?? 'PENDING',
                    'expires_at' => $expiresAt,
                ]
            );

            return response()->json([
                'success' => true,
                'token' => $response['token'] ?? null,
                'expires_in' => $response['expires'] ?? null,
                'status' => $response['status'] ?? null,
                'environment' => $mode,
                'expires_at' => $expiresAt->toIso8601String(),
                'raw_response' => $response,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create Webull token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'raw_response' => null,
            ], 500);
        }
    }
}
