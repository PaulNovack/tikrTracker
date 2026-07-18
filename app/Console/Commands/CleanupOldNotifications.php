<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Command;

class CleanupOldNotifications extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'notifications:cleanup 
                            {--days=30} 
                            {--keep-per-user=100} 
                            {--dry-run}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up old notifications to maintain database performance';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $keepPerUser = (int) $this->option('keep-per-user');
        $dryRun = $this->option('dry-run');

        $this->info('Starting notification cleanup...');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No notifications will actually be deleted');
        }

        // Strategy 1: Delete notifications older than X days
        $oldDate = now()->subDays($days);
        $oldNotificationsQuery = Notification::where('created_at', '<', $oldDate);
        $oldCount = $oldNotificationsQuery->count();

        if ($oldCount > 0) {
            $this->info("Found {$oldCount} notifications older than {$days} days");

            if (! $dryRun) {
                $deleted = $oldNotificationsQuery->delete();
                $this->info("Deleted {$deleted} old notifications");
            }
        }

        // Strategy 2: Keep only the most recent X notifications per user
        $this->info("Checking for users with more than {$keepPerUser} notifications...");

        $users = \App\Models\User::withCount('notifications')
            ->having('notifications_count', '>', $keepPerUser)
            ->get();

        $totalExcessCount = 0;

        foreach ($users as $user) {
            $excessCount = $user->notifications_count - $keepPerUser;
            $totalExcessCount += $excessCount;

            $this->info("User {$user->id} has {$user->notifications_count} notifications (excess: {$excessCount})");

            if (! $dryRun && $excessCount > 0) {
                // Delete the oldest excess notifications for this user
                $idsToDelete = $user->notifications()
                    ->oldest()
                    ->limit($excessCount)
                    ->pluck('id');

                $deleted = Notification::whereIn('id', $idsToDelete)->delete();
                $this->info("Deleted {$deleted} excess notifications for user {$user->id}");
            }
        }

        if ($dryRun) {
            $this->warn("DRY RUN: Would delete approximately {$totalExcessCount} excess notifications");
            $this->warn("DRY RUN: Would delete {$oldCount} old notifications");
            $this->warn('Total notifications that would be cleaned: '.($totalExcessCount + $oldCount));
        }

        // Clear notification count caches for all affected users
        if (! $dryRun) {
            foreach ($users as $user) {
                $cacheKey = sprintf('notification-counts:%d', $user->id);
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
            }
            $this->info('Cleared notification count caches');
        }

        $this->info('Notification cleanup completed!');

        return Command::SUCCESS;
    }
}
