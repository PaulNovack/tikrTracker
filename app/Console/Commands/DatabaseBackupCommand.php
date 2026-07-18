<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DatabaseBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'database:backup {--test : Test mode - show what would be backed up without executing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create hourly database backups using mysqldump with data-only export';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting database backup...');

        // Get database configuration
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port', 3306);
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        // Create backup directory if it doesn't exist
        $backupDir = storage_path('app/database-backups');
        if (! file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
            $this->info("Created backup directory: {$backupDir}");
        }

        // Generate backup filename with timestamp
        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupFile = "{$backupDir}/backup_{$database}_{$timestamp}.sql";

        // Build mysqldump command with your specified arguments
        $mysqldumpCmd = sprintf(
            'mysqldump --host=%s --port=%d --user=%s --password=%s --column-statistics=FALSE --single-transaction=TRUE --skip-triggers %s > %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
            escapeshellarg($backupFile)
        );

        if ($this->option('test')) {
            $this->warn('TEST MODE - Command that would be executed:');
            $this->line($mysqldumpCmd);
            $this->info("Backup would be saved to: {$backupFile}");

            return 0;
        }

        $this->info("Creating backup: {$backupFile}");

        // Execute the backup command
        $output = [];
        $returnCode = 0;
        exec($mysqldumpCmd.' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            $this->error('Backup failed!');
            $this->error('Error output: '.implode("\n", $output));

            Log::error('Database backup failed', [
                'command' => $mysqldumpCmd,
                'return_code' => $returnCode,
                'output' => $output,
                'backup_file' => $backupFile,
            ]);

            return 1;
        }

        // Check if backup file was created and has content
        if (file_exists($backupFile) && filesize($backupFile) > 0) {
            $fileSize = $this->formatBytes(filesize($backupFile));
            $this->info('✅ Backup completed successfully!');
            $this->info("📁 File: {$backupFile}");
            $this->info("📊 Size: {$fileSize}");

            Log::info('Database backup completed successfully', [
                'backup_file' => $backupFile,
                'file_size' => filesize($backupFile),
                'timestamp' => $timestamp,
            ]);

            // Delete all previous backups, keeping only this new one
            $this->cleanupOldBackups($backupDir, $backupFile);

        } else {
            $this->error('Backup file was not created or is empty!');

            Log::error('Database backup file not created or empty', [
                'backup_file' => $backupFile,
                'file_exists' => file_exists($backupFile),
                'file_size' => file_exists($backupFile) ? filesize($backupFile) : 0,
            ]);

            return 1;
        }

        return 0;
    }

    /**
     * Delete all previous backup files, keeping only the one just created.
     */
    private function cleanupOldBackups(string $backupDir, string $keepFile): void
    {
        $files = glob($backupDir.'/backup_*.sql');
        $deletedCount = 0;

        foreach ($files as $file) {
            if (realpath($file) !== realpath($keepFile)) {
                unlink($file);
                $deletedCount++;
            }
        }

        if ($deletedCount > 0) {
            $this->info("🗑️  Deleted {$deletedCount} old backup file(s)");
            Log::info('Old database backups deleted', ['deleted_count' => $deletedCount]);
        }
    }

    /**
     * Format bytes into human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
