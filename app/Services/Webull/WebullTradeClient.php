<?php

namespace App\Services\Webull;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebullTradeClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $appKey = null,
        private readonly ?string $appSecret = null,
        private readonly ?string $accessToken = null,
    ) {}

    protected function http(): PendingRequest
    {
        $baseUrl = $this->baseUrl ?? config('webull.base_url');
        $timeout = 20;

        return Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Place a stock/ETF order (Webull OpenAPI):
     * POST /openapi/trade/stock/order/place
     *
     * Note: new_orders must be an array of order objects, even for single orders.
     */
    public function placeOrder(string $accountId, array $newOrders): array
    {
        $uri = '/openapi/trade/stock/order/place';

        // Ensure new_orders is always an array
        if (isset($newOrders['client_order_id'])) {
            // Single order passed as object, wrap it in array
            $newOrders = [$newOrders];
        }

        $body = [
            'account_id' => $accountId,
            'new_orders' => $newOrders,
        ];

        return $this->request('POST', $uri, [], $body);
    }

    /**
     * Generic signed request wrapper.
     *
     * @param  array<string,string|int|float|bool|null>  $query
     * @param  array<string,mixed>  $jsonBody
     */
    public function request(string $method, string $uri, array $query = [], array $jsonBody = []): array
    {
        $appKey = $this->appKey ?? config('webull.app_key');
        $appSecret = $this->appSecret ?? config('webull.app_secret');
        $accessToken = $this->accessToken ?? value(config('webull.access_token'));

        if (! $appKey || ! $appSecret) {
            throw new \RuntimeException('Webull app_key/app_secret not configured.');
        }

        $host = parse_url(($this->baseUrl ?? config('webull.base_url')), PHP_URL_HOST) ?: 'api.webull.com';

        // Webull wants an ISO8601 UTC timestamp like 2022-01-04T03:55:31Z
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $nonce = Str::uuid()->toString();
        $nonce = str_replace('-', '', $nonce);

        $sigAlg = config('webull.signature_algorithm', 'HMAC-SHA1');
        $sigVer = config('webull.signature_version', '1.0');

        // Build headers that participate in signing (plus host)
        $signHeaders = [
            'x-app-key' => $appKey,
            'x-signature-algorithm' => $sigAlg,
            'x-signature-version' => $sigVer,
            'x-signature-nonce' => $nonce,
            'x-timestamp' => $timestamp,
            'host' => $host,
        ];

        // Some deployments expect token header(s). Keep outside signing unless your Webull setup says otherwise.
        // (Docs list signature headers explicitly; token header isn't listed there.)
        $sendHeaders = $signHeaders;

        if ($accessToken) {
            $sendHeaders['x-access-token'] = $accessToken;
        }

        // x-version header is REQUIRED for Webull API
        $sendHeaders['x-version'] = 'v2';

        $json = $this->jsonMin($jsonBody);
        $signature = $this->computeSignature(
            uri: $uri,
            query: $query,
            signHeaders: $signHeaders,
            bodyJson: $json,
            appSecret: $appSecret
        );

        $sendHeaders['x-signature'] = $signature;

        try {
            $resp = $this->http()
                ->withHeaders($sendHeaders)
                ->send($method, $uri, [
                    'query' => $query,
                    'body' => $json,
                ])
                ->throw();

            return $resp->json() ?? [];
        } catch (RequestException $e) {
            // Webull errors are JSON with { error_code, message }
            $payload = $e->response?->json();
            $code = is_array($payload) ? ($payload['error_code'] ?? null) : null;
            $msg = is_array($payload) ? ($payload['message'] ?? null) : null;

            $status = $e->response?->status();
            $detail = $msg ? "{$code}: {$msg}" : ($e->getMessage());

            throw new \RuntimeException("Webull API error (HTTP {$status}): {$detail}", 0, $e);
        }
    }

    /**
     * Compute Webull signature per docs
     *
     * - s1: sorted k=v from (query params + signature headers + host)
     * - s2: upper(md5(body))
     * - s3: uri + "&" + s1 + "&" + s2
     * - encoded_sign_string = encodeURIComponent(s3)
     * - signature = base64(HMAC-SHA1(app_secret + "&", encoded_sign_string))
     */
    private function computeSignature(
        string $uri,
        array $query,
        array $signHeaders,
        string $bodyJson,
        string $appSecret
    ): string {
        // 1) Build map of query + signHeaders (includes host)
        $map = [];

        foreach ($query as $k => $v) {
            if ($v === null) {
                continue;
            }
            $map[(string) $k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }

        foreach ($signHeaders as $k => $v) {
            $map[(string) $k] = (string) $v;
        }

        ksort($map, SORT_STRING);

        $pairs = [];
        foreach ($map as $k => $v) {
            // IMPORTANT: Webull says values are NOT urlencoded when building s1
            $pairs[] = $k.'='.$v;
        }

        $s1 = implode('&', $pairs);

        // 2) s2 = upper(md5(body))
        $s2 = strtoupper(md5($bodyJson));

        // 3) s3
        $s3 = $uri.'&'.$s1.'&'.$s2;

        // 4) encodeURIComponent (close to RFC3986 with ~ unencoded)
        $encoded = $this->encodeURIComponent($s3);

        // 5) signature = base64(HMAC-SHA1(app_secret + "&", encoded_sign_string))
        $key = $appSecret.'&';
        $raw = hash_hmac('sha1', $encoded, $key, true);

        return base64_encode($raw);
    }

    /**
     * Webull warns JSON spacing can break signature validation.
     */
    private function jsonMin(array $data): string
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new \RuntimeException('Failed to JSON encode request body: '.json_last_error_msg());
        }

        return $json;
    }

    /**
     * Approximation of JS encodeURIComponent with uppercase hex and "~" preserved.
     */
    private function encodeURIComponent(string $s): string
    {
        $enc = rawurlencode($s);          // RFC3986-ish, uppercase hex in PHP
        $enc = str_replace('%7E', '~', $enc); // JS encodeURIComponent does not encode "~"

        return $enc;
    }
}
