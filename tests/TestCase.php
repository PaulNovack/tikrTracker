<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Detect and set the correct APP_URL for both Apache and Laravel dev server
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Auto-detect if we're running on Laravel dev server or Apache
        $this->detectAndSetAppUrl();
    }

    /**
     * Auto-detect the correct APP_URL based on environment
     */
    protected function detectAndSetAppUrl(): void
    {
        // List of potential URLs to test
        $potentialUrls = [
            'http://127.0.0.1:8000',  // Laravel dev server
            'http://localhost:8000',   // Laravel dev server alternative
            'http://localhost',        // Apache default
            'http://127.0.0.1',       // Apache alternative
        ];

        foreach ($potentialUrls as $url) {
            if ($this->isLaravelAppRunning($url)) {
                config(['app.url' => $url]);
                // Set a test flag to know which URL we're using
                config(['testing.detected_url' => $url]);

                return;
            }
        }

        // Fallback
        config(['app.url' => 'http://localhost']);
        config(['testing.detected_url' => 'http://localhost (fallback)']);
    }

    /**
     * Check if Laravel app is running and responsive at the given URL
     */
    protected function isLaravelAppRunning(string $url): bool
    {
        try {
            // Parse URL to get host and port
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? 'localhost';
            $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);

            // First check if port is open
            if (! $this->isPortOpen($host, $port)) {
                return false;
            }

            // Then try to make an actual HTTP request to verify Laravel is running
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'method' => 'GET',
                    'header' => "User-Agent: Laravel-Test-Detection\r\n",
                ],
            ]);

            $response = @file_get_contents($url, false, $context);

            // Check if response looks like a Laravel app (contains common Laravel indicators)
            return $response !== false && (
                strpos($response, 'Laravel') !== false ||
                strpos($response, 'csrf-token') !== false ||
                strpos($response, 'TikrTracker') !== false
            );

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a port is open and responsive
     */
    protected function isPortOpen(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);

            return true;
        }

        return false;
    }

    /**
     * Clean up parallel test databases
     */
    public static function cleanupParallelTestDatabases(): void
    {
        try {
            $dbConfig = config('database.connections.mysql');
            $pdo = new \PDO(
                "mysql:host={$dbConfig['host']};port={$dbConfig['port']}",
                $dbConfig['username'],
                $dbConfig['password']
            );

            // Find all parallel test databases
            $stmt = $pdo->query("SHOW DATABASES LIKE 'laravelInvestTest_test_%'");
            $databases = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($databases as $database) {
                $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
            }
        } catch (\Exception $e) {
            // Silently ignore cleanup errors in tests
        }
    }
}
