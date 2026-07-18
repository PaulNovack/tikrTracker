<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Command;

class CleanupNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:cleanup {--days=30 : Number of days to keep notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old notifications to prevent database bloat';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->subDays($days);

        // First, permanently delete soft-deleted notifications older than cutoff
        $softDeletedCount = Notification::onlyTrashed()
            ->where('deleted_at', '<', $cutoffDate)
            ->count();

        if ($softDeletedCount > 0) {
            $this->info("Found {$softDeletedCount} soft-deleted notifications to permanently delete...");

            // Delete in chunks to avoid memory issues
            $deleted = 0;
            do {
                $chunkDeleted = Notification::onlyTrashed()
                    ->where('deleted_at', '<', $cutoffDate)
                    ->limit(1000)
                    ->forceDelete(); // Permanently delete

                $deleted += $chunkDeleted;

                if ($chunkDeleted > 0) {
                    $this->info("Permanently deleted {$chunkDeleted} notifications (Total: {$deleted})");
                }

                // Small delay to prevent overwhelming the database
                usleep(100000); // 0.1 second

            } while ($chunkDeleted > 0);

            $this->info("✅ Permanently deleted {$deleted} soft-deleted notifications.");
        }

        // Count active notifications to be deleted
        $notificationsToDelete = Notification::where('created_at', '<', $cutoffDate)->count();

        if ($notificationsToDelete === 0) {
            $this->info('No old active notifications found to clean up.');
        } else {
            $this->info("Found {$notificationsToDelete} active notifications older than {$days} days.");

            // For large datasets, delete in chunks to avoid memory issues
            $deleted = 0;
            do {
                $chunkDeleted = Notification::where('created_at', '<', $cutoffDate)
                    ->limit(1000)
                    ->delete(); // Soft delete

                $deleted += $chunkDeleted;

                if ($chunkDeleted > 0) {
                    $this->info("Soft deleted {$chunkDeleted} notifications (Total: {$deleted})");
                }

                // Small delay to prevent overwhelming the database
                usleep(100000); // 0.1 second

            } while ($chunkDeleted > 0);

            $this->info("✅ Cleanup completed. Soft deleted {$deleted} old notifications.");
        }

        // Additionally, keep only the latest 100 notifications per user for active users
        $this->info('Keeping only the latest 100 notifications per user...');

        $users = \App\Models\User::withCount('notifications')
            ->having('notifications_count', '>', 100)
            ->get();

        foreach ($users as $user) {
            $notificationsToKeep = $user->notifications()
                ->latest()
                ->limit(100)
                ->pluck('id');

            $deletedForUser = $user->notifications()
                ->whereNotIn('id', $notificationsToKeep)
                ->delete();

            if ($deletedForUser > 0) {
                $this->info("Deleted {$deletedForUser} excess notifications for user {$user->id}");
            }
        }

        return 0;
    }
}
