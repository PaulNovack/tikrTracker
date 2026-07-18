<?php

namespace App\Services\MarketData;

use Carbon\CarbonInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class AlpacaMarketDataService
{
    private Client $http;

    public function __construct()
    {
        $base = rtrim(config('alpaca.data_base', 'https://data.alpaca.markets'), '/');

        // Create handler stack with retry middleware for network failures
        $handlerStack = HandlerStack::create();
        $handlerStack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));

        $this->http = new Client([
            'base_uri' => $base,
            'timeout' => 20,
            'connect_timeout' => 10,
            'handler' => $handlerStack,
            'headers' => [
                'APCA-API-KEY-ID' => config('alpaca.key_id'),
                'APCA-API-SECRET-KEY' => config('alpaca.secret_key'),
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Decide whether to retry a failed request
     */
    private function retryDecider(): callable
    {
        return function (
            int $retries,
            Request $request,
            ?Response $response = null,
            ?\Throwable $exception = null
        ) {
            // Retry up to 3 times
            if ($retries >= 3) {
                return false;
            }

            // Retry on connection errors (internet down, server unreachable)
            if ($exception instanceof ConnectException) {
                return true;
            }

            // Retry on 5xx server errors or 429 rate limit
            if ($response && $response->getStatusCode() >= 500) {
                return true;
            }

            if ($response && $response->getStatusCode() === 429) {
                return true;
            }

            return false;
        };
    }

    /**
     * Delay before retry (exponential backoff)
     */
    private function retryDelay(): callable
    {
        return function (int $retries) {
            return 1000 * pow(2, $retries); // 1s, 2s, 4s
        };
    }

    /**
     * Fetch 1-minute bars for many symbols (supports pagination).
     * Returns: ['bars' => keyed by symbol, 'next_page_token' => string|null]
     */
    public function get1mBars(array $symbols, CarbonInterface $startUtc, CarbonInterface $endUtc, ?string $feed = null, ?string $pageToken = null, int $limit = 10000): array
    {
        $symbols = array_values(array_unique(array_filter(array_map('trim', $symbols))));
        if (! $symbols) {
            return ['bars' => [], 'next_page_token' => null];
        }

        $query = [
            'symbols' => implode(',', $symbols),
            'timeframe' => '1Min',
            'start' => $startUtc->toIso8601String(),
            'end' => $endUtc->toIso8601String(),
            'limit' => $limit,
            'feed' => $feed ?: config('alpaca.feed', 'iex'),
        ];

        if ($pageToken) {
            $query['page_token'] = $pageToken;
        }

        $resp = $this->http->get('/v2/stocks/bars', ['query' => $query]);
        $json = json_decode((string) $resp->getBody(), true);

        return [
            'bars' => $json['bars'] ?? [],
            'next_page_token' => $json['next_page_token'] ?? null,
        ];
    }

    /**
     * Fetch 5-minute bars for many symbols (supports pagination).
     * Returns: ['bars' => keyed by symbol, 'next_page_token' => string|null]
     */
    public function get5mBars(array $symbols, CarbonInterface $startUtc, CarbonInterface $endUtc, ?string $feed = null, ?string $pageToken = null, int $limit = 10000): array
    {
        $symbols = array_values(array_unique(array_filter(array_map('trim', $symbols))));
        if (! $symbols) {
            return ['bars' => [], 'next_page_token' => null];
        }

        $query = [
            'symbols' => implode(',', $symbols),
            'timeframe' => '5Min',
            'start' => $startUtc->toIso8601String(),
            'end' => $endUtc->toIso8601String(),
            'limit' => $limit,
            'feed' => $feed ?: config('alpaca.feed', 'iex'),
        ];

        if ($pageToken) {
            $query['page_token'] = $pageToken;
        }

        $resp = $this->http->get('/v2/stocks/bars', ['query' => $query]);
        $json = json_decode((string) $resp->getBody(), true);

        return [
            'bars' => $json['bars'] ?? [],
            'next_page_token' => $json['next_page_token'] ?? null,
        ];
    }

    /**
     * Fetch daily bars for many symbols (supports pagination).
     * Returns: ['bars' => keyed by symbol, 'next_page_token' => string|null]
     */
    public function getDailyBars(array $symbols, CarbonInterface $startUtc, CarbonInterface $endUtc, ?string $feed = null, ?string $pageToken = null, int $limit = 10000): array
    {
        $symbols = array_values(array_unique(array_filter(array_map('trim', $symbols))));
        if (! $symbols) {
            return ['bars' => [], 'next_page_token' => null];
        }

        $query = [
            'symbols' => implode(',', $symbols),
            'timeframe' => '1Day',
            'start' => $startUtc->toIso8601String(),
            'end' => $endUtc->toIso8601String(),
            'limit' => $limit,
            'feed' => $feed ?: config('alpaca.feed', 'iex'),
            'adjustment' => 'all', // Use adjusted bars (splits, dividends)
        ];

        if ($pageToken) {
            $query['page_token'] = $pageToken;
        }

        $resp = $this->http->get('/v2/stocks/bars', ['query' => $query]);
        $json = json_decode((string) $resp->getBody(), true);

        return [
            'bars' => $json['bars'] ?? [],
            'next_page_token' => $json['next_page_token'] ?? null,
        ];
    }
}
