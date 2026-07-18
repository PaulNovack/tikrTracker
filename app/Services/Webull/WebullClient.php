<?php

namespace App\Services\Webull;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebullClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appKey,
        private readonly string $appSecret,
        private readonly ?string $region = 'US',
    ) {}

    public function get(string $path, array $query = []): Response
    {
        return $this->request('GET', $path, $query, null);
    }

    public function post(string $path, array $query = [], array $jsonBody = []): Response
    {
        return $this->request('POST', $path, $query, $jsonBody);
    }

    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): Response
    {
        $url = rtrim($this->baseUrl, '/').$path;

        $timestamp = now('UTC')->format('Y-m-d\TH:i:s\Z'); // RFC-3339 UTC as required :contentReference[oaicite:5]{index=5}
        $nonce = Str::uuid()->toString();

        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        $port = parse_url($this->baseUrl, PHP_URL_PORT);
        $hostHeader = $port ? "{$host}:{$port}" : $host;

        $signatureHeaders = [
            'x-app-key' => $this->appKey,
            'x-signature-algorithm' => 'HMAC-SHA1',
            'x-signature-version' => '1.0',
            'x-signature-nonce' => str_replace('-', '', $nonce),
            'x-timestamp' => $timestamp,
            'host' => $hostHeader,
        ];

        $bodyJson = $jsonBody !== null ? json_encode($jsonBody, JSON_UNESCAPED_SLASHES) : '';
        $signature = $this->sign($path, $query, $signatureHeaders, $bodyJson);

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json', // required for POST :contentReference[oaicite:6]{index=6}
            'x-signature' => $signature,
            // Include signature headers in the actual request
            'x-app-key' => $signatureHeaders['x-app-key'],
            'x-signature-algorithm' => $signatureHeaders['x-signature-algorithm'],
            'x-signature-version' => $signatureHeaders['x-signature-version'],
            'x-signature-nonce' => $signatureHeaders['x-signature-nonce'],
            'x-timestamp' => $signatureHeaders['x-timestamp'],
            'Host' => $signatureHeaders['host'],
        ];

        $client = Http::withHeaders($headers)->timeout(20);

        if ($method === 'GET') {
            return $client->get($url, $query);
        }

        // POST
        return $client->post($url.($query ? ('?'.http_build_query($query)) : ''), $jsonBody ?? []);
    }

    /**
     * Implements Webull OpenAPI signature rules. :contentReference[oaicite:7]{index=7}
     */
    private function sign(string $path, array $queryParams, array $sigHeaders, string $bodyJson): string
    {
        // 1) Collect params (query + signature headers), sort by name asc
        $params = [];

        foreach ($queryParams as $k => $v) {
            $params[(string) $k] = is_array($v) ? implode('&', array_map('strval', $v)) : (string) $v;
        }
        foreach ($sigHeaders as $k => $v) {
            // Signature doc includes these headers in signing :contentReference[oaicite:8]{index=8}
            $params[(string) $k] = (string) $v;
        }

        ksort($params, SORT_STRING);

        // 2) name=value&name2=value2
        $str1Parts = [];
        foreach ($params as $k => $v) {
            $str1Parts[] = $k.'='.$v;
        }
        $str1 = implode('&', $str1Parts);

        // 3) str2 = UPPER(MD5(body))
        $str2 = '';
        if ($bodyJson !== '') {
            $str2 = strtoupper(md5($bodyJson));
        }

        // 4) str3 = path & str1 & str2 (if body)
        $str3 = $bodyJson === '' ? ($path.'&'.$str1) : ($path.'&'.$str1.'&'.$str2);

        // 5) URL encode str3
        $encoded = rawurlencode($str3);

        // Key = app_secret + "&" :contentReference[oaicite:9]{index=9}
        $key = $this->appSecret.'&';

        // signature = base64(HMAC-SHA1(key, encoded))
        $hmac = hash_hmac('sha1', $encoded, $key, true);

        return base64_encode($hmac);
    }
}
