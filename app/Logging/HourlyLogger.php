<?php

namespace App\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

class HourlyLogger
{
    /**
     * Create a custom Monolog instance with hourly rotation.
     */
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('sql-hourly');

        // Create custom handler that rotates hourly
        $handler = new class($config) extends StreamHandler
        {
            private array $config;

            private ?string $currentFile = null;

            public function __construct(array $config)
            {
                $this->config = $config;
                $this->initializeStream();
                parent::__construct($this->currentFile, $config['level'] ?? Level::Debug);
            }

            protected function write(\Monolog\LogRecord $record): void
            {
                $this->rotateIfNeeded();
                parent::write($record);
            }

            private function initializeStream(): void
            {
                $basePath = $this->config['path'] ?? storage_path('logs/laravel-sql-hourly.log');
                $pathInfo = pathinfo($basePath);

                $currentHour = now()->format('Y-m-d-H');
                $this->currentFile = $pathInfo['dirname'].'/'.$pathInfo['filename'].'-'.$currentHour.'.'.$pathInfo['extension'];
            }

            private function rotateIfNeeded(): void
            {
                $basePath = $this->config['path'] ?? storage_path('logs/laravel-sql-hourly.log');
                $pathInfo = pathinfo($basePath);

                $currentHour = now()->format('Y-m-d-H');
                $newFile = $pathInfo['dirname'].'/'.$pathInfo['filename'].'-'.$currentHour.'.'.$pathInfo['extension'];

                if ($this->currentFile !== $newFile) {
                    $this->close();
                    $this->currentFile = $newFile;
                    $this->stream = null; // Force recreation of stream
                    $this->url = $this->currentFile;
                }

                // Clean up old files (keep only specified number of hours)
                $this->cleanOldFiles($pathInfo, $this->config['days'] ?? 24);
            }

            private function cleanOldFiles(array $pathInfo, int $hoursToKeep): void
            {
                $pattern = $pathInfo['dirname'].'/'.$pathInfo['filename'].'-*.'.$pathInfo['extension'];
                $files = glob($pattern);

                if (count($files) <= $hoursToKeep) {
                    return;
                }

                // Sort files by modification time
                usort($files, function ($a, $b) {
                    return filemtime($a) <=> filemtime($b);
                });

                // Remove oldest files beyond the retention limit
                $filesToDelete = array_slice($files, 0, count($files) - $hoursToKeep);
                foreach ($filesToDelete as $file) {
                    @unlink($file);
                }
            }
        };

        $logger->pushHandler($handler);
        $logger->pushProcessor(new PsrLogMessageProcessor);

        return $logger;
    }
}
