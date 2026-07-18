<?php

namespace App\Services\Market;

use Illuminate\Support\Facades\Http;

class WikimediaService
{
    private const WIKIMEDIA_API = 'https://en.wikipedia.org/w/api.php';

    /**
     * Fetch description from Wikimedia for a given symbol/company name.
     * Tries multiple search strategies with fallbacks.
     */
    public static function fetchDescription(string $symbol, string $commonName): ?string
    {
        try {
            // Build list of search queries to try in order
            $searchQueries = self::buildSearchQueries($symbol, $commonName);

            foreach ($searchQueries as $query) {
                $description = self::searchAndExtractDescription($query);
                if ($description) {
                    return $description;
                }
            }

            return null;
        } catch (\Exception $e) {
            \Log::warning('Failed to fetch Wikimedia description for '.$symbol.': '.$e->getMessage());

            return null;
        }
    }

    /**
     * Build a list of search queries to try in order of likelihood.
     * Includes variations, truncations, and common fallbacks.
     */
    private static function buildSearchQueries(string $symbol, string $commonName): array
    {
        $queries = [];

        // 1. Original common name (exact match)
        if ($commonName) {
            $queries[] = $commonName;
        }

        // 2. Common name without corporation suffixes
        if ($commonName) {
            $stripped = self::stripCorporateSuffixes($commonName);
            if ($stripped !== $commonName) {
                $queries[] = $stripped;
            }
        }

        // 3. First part of common name (before "Inc.", "Corp.", etc.)
        if ($commonName) {
            $parts = preg_split('/\s+(Inc\.|Corp\.|Ltd\.|LLC|Co\.|Company|Corporation)/i', $commonName);
            if (isset($parts[0]) && $parts[0]) {
                $queries[] = trim($parts[0]);
            }
        }

        // 4. Symbol search (ticker symbol)
        if ($symbol && $symbol !== $commonName) {
            $queries[] = $symbol;
        }

        // 5. Common name with related terms (for specific sectors)
        if ($commonName) {
            $queries[] = $commonName.' company';
            $queries[] = $commonName.' corporation';
            $queries[] = $commonName.' stock';
        }

        // 6. Last resort: just symbol with company term
        if ($symbol && $symbol !== $commonName) {
            $queries[] = $symbol.' company';
        }

        // Remove duplicates and empty values
        $queries = array_unique(array_filter($queries));

        return $queries;
    }

    /**
     * Strip common corporate suffixes from a company name.
     */
    private static function stripCorporateSuffixes(string $name): string
    {
        $suffixes = [
            ' Inc\.', ' Inc',
            ' Corp\.', ' Corp', ' Corporation',
            ' Ltd\.', ' Ltd', ' Limited',
            ' LLC', ' L\.L\.C',
            ' Co\.', ' Co',
            ' Company',
            ' PLC', ' Plc',
            ' SE', ' SA', ' AG',
            ' GmbH',
            ' N\.V\.', ' N\.A\.',
            ' Group',
        ];

        foreach ($suffixes as $suffix) {
            $name = preg_replace('/'.preg_quote($suffix, '/').'$/i', '', $name);
        }

        return trim($name);
    }

    /**
     * Search Wikipedia and extract the description from the first matching result.
     */
    private static function searchAndExtractDescription(string $searchQuery): ?string
    {
        if (! $searchQuery) {
            return null;
        }

        try {
            // Search for the query (with User-Agent for Wikipedia API compliance)
            $response = Http::withHeaders([
                'User-Agent' => 'Laravel-Invest-App/1.0 (stock-market-tracking)',
            ])->timeout(10)
                ->get(self::WIKIMEDIA_API, [
                    'action' => 'query',
                    'format' => 'json',
                    'list' => 'search',
                    'srsearch' => $searchQuery,
                    'srwhat' => 'text',
                    'srlimit' => 3,  // Get top 3 results for better matching
                    'utf8' => 1,
                ]);

            $data = $response->json();

            if (! isset($data['query']['search']) || count($data['query']['search']) === 0) {
                return null;
            }

            // Try to get the best match from results
            foreach ($data['query']['search'] as $result) {
                $pageTitle = $result['title'];
                $description = self::extractPageDescription($pageTitle);

                if ($description) {
                    return $description;
                }
            }

            return null;
        } catch (\Exception $e) {
            \Log::debug('Wikimedia search failed for query "'.$searchQuery.'": '.$e->getMessage());

            return null;
        }
    }

    /**
     * Extract the intro/description from a Wikipedia page by its title.
     */
    private static function extractPageDescription(string $pageTitle): ?string
    {
        try {
            // Get the full page content (with User-Agent for Wikipedia API compliance)
            $pageResponse = Http::withHeaders([
                'User-Agent' => 'Laravel-Invest-App/1.0 (stock-market-tracking)',
            ])->timeout(10)
                ->get(self::WIKIMEDIA_API, [
                    'action' => 'query',
                    'format' => 'json',
                    'titles' => $pageTitle,
                    'prop' => 'extracts',
                    'explaintext' => 1,
                    'exintro' => 1,
                    'utf8' => 1,
                ]);

            $pageData = $pageResponse->json();

            if (! isset($pageData['query']['pages'])) {
                return null;
            }

            // Get the first page
            $pages = $pageData['query']['pages'];
            $page = reset($pages);

            $extract = $page['extract'] ?? null;

            // Return only if we have meaningful content (more than just redirect info)
            if ($extract && strlen(trim($extract)) > 50) {
                return $extract;
            }

            return null;
        } catch (\Exception $e) {
            \Log::debug('Failed to extract description from "'.$pageTitle.'": '.$e->getMessage());

            return null;
        }
    }
}
