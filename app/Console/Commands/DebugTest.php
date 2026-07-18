<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DebugTest extends Command
{
    protected $signature = 'debug:test {--message=Hello}';

    protected $description = 'Test command for debugging setup';

    public function handle(): int
    {
        $message = $this->option('message');

        // Set a breakpoint here to test debugging
        $this->info('Debug test starting...');

        $data = [
            'message' => $message,
            'timestamp' => now()->toDateTimeString(),
            'user' => 'developer',
            'debug' => true,
        ];

        // Another good place for a breakpoint
        foreach ($data as $key => $value) {
            $this->line("$key: $value");
        }

        $this->info('Debug test completed successfully!');

        return 0;
    }
}
