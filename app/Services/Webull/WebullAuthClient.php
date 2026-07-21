<?php

namespace App\Services\Webull;

class WebullAuthClient
{
    public function __construct(private readonly WebullSignedHttp $http) {}

    /**
     * Create Token
     * POST /openapi/auth/token/create
     *
     * For Individual API, requires username and password in the payload.
     * Returns a token with PENDING status that needs SMS verification via Webull App.
     */
    public function createToken(?array $payload = null): array
    {
        // If no payload provided, use username/password from config
        if ($payload === null) {
            $payload = [
                'username' => config('webull.username'),
                'password' => config('webull.password'),
            ];
        }

        return $this->http->request('POST', '/openapi/auth/token/create', [], $payload, null);
    }

    /**
     * Check Token
     * POST /openapi/auth/token/check
     */
    public function checkToken(string $token): array
    {
        return $this->http->request('POST', '/openapi/auth/token/check', [], [
            'token' => $token,
        ], null);
    }

    /**
     * "Refresh" token: in practice, create a new token before expiry.
     * (Docs mention tokens should be refreshed before expiration, but do not show a dedicated refresh endpoint.)
     */
    public function refreshToken(array $payload): array
    {
        return $this->createToken($payload);
    }
}
