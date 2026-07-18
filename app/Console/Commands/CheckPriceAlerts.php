<?php

namespace App\Console\Commands;

use App\Mail\PriceAlertNotification;
use App\Models\AlertLog;
use App\Models\FiveMinutePrice;
use App\Models\Notification;
use App\Models\PriceAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class CheckPriceAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-price-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check price alerts and send notifications when prices cross thresholds';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $alerts = PriceAlert::where(function ($query) {
            $query->where('up_enabled', true)->orWhere('down_enabled', true);
        })->with('asset', 'user')->get();

        $this->info("Checking {$alerts->count()} price alerts...");

        foreach ($alerts as $alert) {
            $this->checkAlert($alert);
            // Add small delay between alerts to avoid rate limiting
            usleep(500000); // 0.5 seconds between each alert check
        }

        $this->info('Price alert check completed.');

        return 0;
    }

    private function checkAlert(PriceAlert $alert): void
    {
        // Get the latest 5-minute price for this asset (most current data)
        $latestPrice = FiveMinutePrice::where('symbol', $alert->asset->symbol)
            ->where('asset_type', $alert->asset->asset_type)
            ->latest('ts')
            ->first();

        if (! $latestPrice) {
            return;
        }

        $currentPrice = (float) $latestPrice->price;
        $abovePrice = (float) $alert->above_price;
        $belowPrice = (float) $alert->below_price;

        // Check if up alert is enabled and price crossed above threshold
        if ($alert->up_enabled && ! $alert->above_triggered && $currentPrice >= $abovePrice) {
            $this->createNotification(
                $alert->user_id,
                "{$alert->asset->symbol} price alert triggered",
                "📈 {$alert->asset->symbol} has reached $".number_format($currentPrice, 2).' - above your alert level of $'.number_format($abovePrice, 2),
                'success',
                $alert->asset->id
            );

            // Calculate trigger percentage
            // For UP alerts: positive number showing how much above trigger price
            $triggerPercentage = (($currentPrice - $abovePrice) / $abovePrice) * 100;
            // Cap at 99999.99 to prevent database overflow
            $triggerPercentage = min($triggerPercentage, 99999.99);

            // Send email notification
            $emailStatus = $this->sendEmailNotification($alert, 'up', $currentPrice);

            // Log the alert
            AlertLog::create([
                'user_id' => $alert->user_id,
                'price_alert_id' => $alert->id,
                'symbol' => $alert->asset->symbol,
                'direction' => 'up',
                'trigger_price' => $abovePrice,
                'current_price' => $currentPrice,
                'trigger_percentage' => $triggerPercentage,
                'email_status' => $emailStatus['status'],
                'email_error' => $emailStatus['error'] ?? null,
                'sent_at' => now(),
            ]);

            $alert->update([
                'above_triggered' => true,
                'last_triggered_at' => now(),
            ]);

            $this->info("✓ Above alert triggered for {$alert->asset->symbol}");
        } elseif ($alert->above_triggered && $currentPrice < $abovePrice) {
            // Reset the above trigger if price falls back below
            $alert->update(['above_triggered' => false]);
        }

        // Check if down alert is enabled and price crossed below threshold
        if ($alert->down_enabled && ! $alert->below_triggered && $currentPrice <= $belowPrice) {
            $this->createNotification(
                $alert->user_id,
                "{$alert->asset->symbol} price alert triggered",
                "📉 {$alert->asset->symbol} has reached $".number_format($currentPrice, 2).' - below your alert level of $'.number_format($belowPrice, 2),
                'success',
                $alert->asset->id
            );

            // Calculate trigger percentage
            // For DOWN alerts: negative number showing how much below trigger price
            $triggerPercentage = (($currentPrice - $belowPrice) / $belowPrice) * 100;
            // Cap at minimum -99999.99 to prevent database overflow
            $triggerPercentage = max($triggerPercentage, -99999.99);

            // Send email notification
            $emailStatus = $this->sendEmailNotification($alert, 'down', $currentPrice);

            // Log the alert
            AlertLog::create([
                'user_id' => $alert->user_id,
                'price_alert_id' => $alert->id,
                'symbol' => $alert->asset->symbol,
                'direction' => 'down',
                'trigger_price' => $belowPrice,
                'current_price' => $currentPrice,
                'trigger_percentage' => $triggerPercentage,
                'email_status' => $emailStatus['status'],
                'email_error' => $emailStatus['error'] ?? null,
                'sent_at' => now(),
            ]);

            $alert->update([
                'below_triggered' => true,
                'last_triggered_at' => now(),
            ]);

            $this->info("✓ Below alert triggered for {$alert->asset->symbol}");
        } elseif ($alert->below_triggered && $currentPrice > $belowPrice) {
            // Reset the below trigger if price rises back above
            $alert->update(['below_triggered' => false]);
        }
    }

    private function createNotification(int $userId, string $title, string $description, string $type, ?int $assetId = null): void
    {
        // Rate limiting: prevent duplicate notifications within 1 hour
        $recentNotification = Notification::where('user_id', $userId)
            ->where('title', $title)
            ->where('created_at', '>=', now()->subHour())
            ->first();

        if ($recentNotification) {
            $this->info("⏱️  Skipping duplicate notification for user {$userId}: {$title}");

            return;
        }

        Notification::create([
            'user_id' => $userId,
            'asset_id' => $assetId,
            'title' => $title,
            'description' => $description,
            'type' => $type,
            'read' => false,
        ]);

        // Invalidate the notification counts cache when a new notification is created
        $cacheKey = sprintf('notification-counts:%d', $userId);
        \Illuminate\Support\Facades\Cache::forget($cacheKey);
    }

    private function sendEmailNotification(PriceAlert $alert, string $direction, float $currentPrice): array
    {
        // Check if email sending is enabled
        if (! config('mail.send_emails')) {
            return ['status' => 'disabled', 'message' => 'Email sending is disabled'];
        }

        $maxRetries = 5;
        $baseDelay = 0.5; // seconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Mail::to($alert->user->email)->send(
                    new PriceAlertNotification($alert, $direction, $currentPrice)
                );

                return ['status' => 'sent']; // Success, return success status
            } catch (\Exception $e) {
                if ($attempt < $maxRetries) {
                    // Calculate longer delays with slower exponential backoff: 0.5s, 1s, 2s, 4s, 8s
                    $delay = $baseDelay * pow(2, $attempt - 1);
                    $this->warn("Failed to send email for {$alert->asset->symbol} (attempt {$attempt}/{$maxRetries}). Retrying in {$delay}s...");
                    usleep((int) ($delay * 1000000)); // Use microseconds for finer control
                } else {
                    // Final attempt failed
                    $errorMessage = $e->getMessage();
                    $this->warn("Failed to send email for {$alert->asset->symbol} after {$maxRetries} attempts: {$errorMessage}");

                    return [
                        'status' => 'failed',
                        'error' => $errorMessage,
                    ];
                }
            }
        }

        return ['status' => 'failed', 'error' => 'Unknown error'];
    }
}
