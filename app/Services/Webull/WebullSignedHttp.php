<?php

namespace App\Services\Webull;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebullSignedHttp
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $appKey = null,
        private readonly ?string $appSecret = null,
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
     * Generic signed request wrapper.
     *
     * @param  array<string,string|int|float|bool|null>  $query
     * @param  array<string,mixed>  $jsonBody
     */
    public function request(
        string $method,
        string $uri,
        array $query = [],
        array $jsonBody = [],
        ?string $accessToken = null
    ): array {
        $appKey = $this->appKey ?? config('webull.app_key');
        $appSecret = $this->appSecret ?? config('webull.app_secret');
        // Don't use config access_token if not provided - Individual API doesn't need it
        // $accessToken is only used if explicitly passed

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
        $sendHeaders = $signHeaders;

        if ($accessToken) {
            $sendHeaders['x-access-token'] = $accessToken;
        }
        // Always add x-version header for v2 endpoints
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
            $httpClient = $this->http()->withHeaders($sendHeaders);

            // For GET/DELETE requests, don't send a body. For POST/PUT/PATCH, send the JSON body.
            if (strtoupper($method) === 'GET' || strtoupper($method) === 'DELETE') {
                $resp = $httpClient->send($method, $uri, [
                    'query' => $query,
                ])->throw();
            } else {
                $resp = $httpClient->send($method, $uri, [
                    'query' => $query,
                    'body' => $json,
                ])->throw();
            }

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
            $pairs[] = $k.'='.$v;
        }

        $s1 = implode('&', $pairs);

        // 2) s2 = upper(md5(body)) - only if body is not empty
        // 3) s3
        if (! empty($bodyJson) && $bodyJson !== '{}' && $bodyJson !== '[]') {
            $s2 = strtoupper(md5($bodyJson));
            $s3 = $uri.'&'.$s1.'&'.$s2;
        } else {
            // If body is empty, s3 = path + "&" + s1 (no MD5 hash)
            $s3 = $uri.'&'.$s1;
        }

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
        $enc = rawurlencode($s);
        $enc = str_replace('%7E', '~', $enc);

        return $enc;
    }
}
