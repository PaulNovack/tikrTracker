<?php

namespace App\Console\Commands\Webull;

use App\Models\WebullToken;
use App\Services\Webull\WebullAuthClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckTokenStatus extends Command
{
    protected $signature = 'webull:token-status 
                            {--environment=DEV : The environment (DEV or PROD)}
                            {--force-normal : Force set token status to NORMAL (for UAT testing)}';

    protected $description = 'Check Webull token status and optionally force it to NORMAL for UAT testing';

    public function handle(WebullAuthClient $authClient): int
    {
        $environment = strtoupper($this->option('environment'));

        $token = WebullToken::where('environment', $environment)->first();

        if (! $token) {
            $this->error("No token found for {$environment} environment");
            $this->info('Run the token creation from the web UI: http://127.0.0.1:8080/orders/webull-account');

            return 1;
        }

        $this->info("Token found for {$environment} environment:");
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $token->id],
                ['Status', $token->status],
                ['Token', substr($token->token, 0, 10).'...'],
                ['Expires At', $token->expires_at->toDateTimeString()],
                ['Expires In', $token->expires_at->diffForHumans()],
                ['Is Expired', $token->isExpired() ? 'Yes' : 'No'],
            ]
        );

        // Check token status via API
        $this->newLine();
        $this->info('Checking token status with Webull API...');

        try {
            $response = $authClient->checkToken($token->token);

            $this->table(
                ['Field', 'Value'],
                [
                    ['API Status', $response['status'] ?? 'Unknown'],
                    ['API Expires', isset($response['expires']) ? Carbon::createFromTimestampMs($response['expires'])->toDateTimeString() : 'Unknown'],
                ]
            );

            // Update database if status changed
            if (isset($response['status']) && $response['status'] !== $token->status) {
                $token->update(['status' => $response['status']]);
                $this->info("Updated token status in database to: {$response['status']}");
            }

        } catch (\Exception $e) {
            $this->error("Failed to check token with API: {$e->getMessage()}");
        }

        // Force to NORMAL if requested (for UAT testing)
        if ($this->option('force-normal') && $token->status !== 'NORMAL') {
            $this->newLine();
            $this->warn('WARNING: Forcing token status to NORMAL for UAT testing');
            $this->warn('This bypasses SMS verification and should ONLY be used in UAT/test environments');

            if ($this->confirm('Are you sure you want to force the token status to NORMAL?')) {
                $token->update(['status' => 'NORMAL']);
                $this->info('Token status forced to NORMAL');
                $this->warn('Note: If the token is still invalid (PENDING), orders will still fail with 401 errors');
                $this->warn('You may need to contact Webull support to get a valid UAT token');
            }
        }

        $this->newLine();
        if ($token->status === 'PENDING') {
            $this->warn('⚠️  Token is PENDING and requires SMS verification');
            $this->info('For UAT testing, you may need to:');
            $this->info('  1. Contact Webull support for a pre-verified UAT token');
            $this->info('  2. Use the --force-normal flag (may not work if token is genuinely invalid)');
            $this->info('  3. Verify via mobile app if you have UAT access');
        } elseif ($token->status === 'NORMAL') {
            $this->info('✓ Token status is NORMAL and should work for orders');
        }

        return 0;
    }
}
