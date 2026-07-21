<?php

namespace App\Services\Webull;

class WebullAccountClient
{
    public function __construct(
        private readonly WebullSignedHttp $http
    ) {}

    /**
     * List all trading accounts associated with your Webull credentials.
     *
     * @return array<int, array{
     *     accountId: string,
     *     accountNumber: string,
     *     accountType: string,
     *     accountName?: string,
     *     ...
     * }>
     */
    public function listAccounts(?string $accessToken = null): array
    {
        $data = $this->http->request(
            method: 'GET',
            uri: '/openapi/account/list',
            query: [],
            jsonBody: [],
            accessToken: $accessToken
        );

        return $data ?? [];
    }

    /**
     * Find an account ID by (partial) name, e.g. "My Paper".
     */
    public function getAccountIdByName(string $partialName, ?string $accessToken = null): ?string
    {
        $accounts = $this->listAccounts($accessToken);

        $lower = strtolower($partialName);

        foreach ($accounts as $acct) {
            $name = strtolower($acct['accountName'] ?? '');
            if (str_contains($name, $lower)) {
                return (string) $acct['accountId'];
            }
        }

        return null;
    }

    /**
     * Find the first account matching a given accountType (PAPER, MARGIN, CASH, etc.).
     */
    public function getAccountIdByType(string $type, ?string $accessToken = null): ?string
    {
        $accounts = $this->listAccounts($accessToken);

        $upper = strtoupper($type);

        foreach ($accounts as $acct) {
            if (strtoupper($acct['accountType'] ?? '') === $upper) {
                return (string) $acct['accountId'];
            }
        }

        return null;
    }

    /**
     * Return the first account (if any).
     */
    public function getDefaultAccountId(?string $accessToken = null): ?string
    {
        $accounts = $this->listAccounts($accessToken);

        return ! empty($accounts) ? (string) $accounts[0]['accountId'] : null;
    }
}
