<?php

namespace App\Console\Commands;

use App\Models\CpuTemperatureReading;
use Illuminate\Console\Command;

class RecordCpuTemperature extends Command
{
    protected $signature = 'cpu:record-temperature';

    protected $description = 'Run sensors command and persist CPU temperature readings to the database';

    public function handle(): int
    {
        $command = 'sensors 2>&1';
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->error('sensors command failed');

            return self::FAILURE;
        }

        $temperatures = $this->parseTemperatures($output);

        if ($temperatures === []) {
            $this->warn('No temperature readings found');

            return self::SUCCESS;
        }

        $refreshedAt = now('America/New_York');
        $rows = array_map(static function (array $reading) use ($refreshedAt): array {
            return [
                'refreshed_at' => $refreshedAt,
                'sensor_section' => $reading['section'],
                'sensor_label' => $reading['label'],
                'temperature_celsius' => $reading['value'],
                'raw_reading' => $reading['raw'],
                'created_at' => $refreshedAt,
                'updated_at' => $refreshedAt,
            ];
        }, $temperatures);

        CpuTemperatureReading::query()->insert($rows);

        $this->info(sprintf(
            'Recorded %d temperature readings at %s',
            count($rows),
            $refreshedAt->toDateTimeString()
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{section: string, label: string, value: float, raw: string}>
     */
    private function parseTemperatures(array $output): array
    {
        $temperatures = [];
        $currentSection = 'Unknown';

        foreach ($output as $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '') {
                continue;
            }

            // Detect section headers (non-indented lines that aren't readings)
            $isReadingLine = preg_match('/^[^:]+:\s+.+$/', $trimmedLine) === 1;

            if (! str_starts_with($line, ' ') && ! str_starts_with($line, "\t")
                && ! str_starts_with($trimmedLine, 'Adapter:')
                && ! $isReadingLine
            ) {
                $currentSection = $trimmedLine;

                continue;
            }

            // Skip adapter lines
            if (str_starts_with($trimmedLine, 'Adapter:')) {
                continue;
            }

            // Match temperature readings: label: +45.0°C or label: 45.0°C
            if (preg_match('/^([^:]+):\s+([+-]?\d+(?:\.\d+)?)(?:\s*°\s*|\s*)C\b/i', $trimmedLine, $matches)) {
                $temperatures[] = [
                    'section' => $currentSection,
                    'label' => trim($matches[1]),
                    'value' => (float) $matches[2],
                    'raw' => $trimmedLine,
                ];
            }
        }

        return $temperatures;
    }
}
