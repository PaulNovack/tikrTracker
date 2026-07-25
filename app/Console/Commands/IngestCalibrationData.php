<?php

namespace App\Console\Commands;

use App\Models\MlProbabilityCalibration;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class IngestCalibrationData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ml:ingest-calibration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse latest training logs and populate ml_probability_calibration with bucket-level win-rate data per pipeline.';

    /**
     * Pipeline → config key for version resolution.
     *
     * @var array<string, string>
     */
    private const PIPELINE_VERSION_KEYS = [
        'A' => 'app.trade_alert_a_version',
        'B' => 'app.trade_alert_b_version',
        'C' => 'app.trade_alert_c_version',
        'D' => 'app.trade_alert_d_version',
        'E' => 'app.trade_alert_e_version',
        'F' => 'app.trade_alert_f_version',
        'G' => 'app.trade_alert_g_version',
        'H' => 'app.trade_alert_h_version',
        'I' => 'app.trade_alert_i_version',
        'J' => 'app.trade_alert_j_version',
        'K' => 'app.trade_alert_k_version',
        'L' => 'app.trade_alert_l_version',
        'M' => 'app.trade_alert_m_version',
        'N' => 'app.trade_alert_n_version',
        'O' => 'app.trade_alert_o_version',
        'P' => 'app.trade_alert_p_version',
        'Q' => 'app.trade_alert_q_version',
        'R' => 'app.trade_alert_r_version',
    ];

    public function handle(): int
    {
        $logsDir = base_path('python_ml/v2/training_logs');

        if (! File::isDirectory($logsDir)) {
            $this->error("Training logs directory not found: {$logsDir}");

            return self::FAILURE;
        }

        $logFiles = File::files($logsDir);
        $grouped = $this->groupLatestPerPipeline($logFiles);

        if ($grouped->isEmpty()) {
            $this->warn('No training log files found.');

            return self::SUCCESS;
        }

        $this->info('Found '.$grouped->count().' pipeline(s) with log files.');

        $inserted = 0;

        foreach ($grouped as $pipeline => $filePath) {
            $this->line("  {$pipeline} ← ".basename((string) $filePath));

            $rows = $this->parseBuckets((string) $filePath);

            if ($rows === []) {
                $this->warn('    No bucket data found — skipping.');

                continue;
            }

            $version = $this->resolveVersion($pipeline);

            // Replace existing rows for this pipeline + version
            MlProbabilityCalibration::where('pipeline', $pipeline)
                ->where('version', $version)
                ->delete();

            foreach ($rows as $row) {
                MlProbabilityCalibration::create(array_merge($row, [
                    'pipeline' => $pipeline,
                    'version' => $version,
                ]));
                $inserted++;
            }

            $this->info("    ✔ v{$version} — ".count($rows).' buckets');
        }

        $this->newLine();
        $this->info("Done. {$inserted} rows for ".$grouped->count().' pipeline(s).');

        return self::SUCCESS;
    }

    /**
     * Group log files by pipeline prefix, picking the latest COMPLETE log
     * per pipeline. A log is considered complete if it contains the
     * "=== Probability Buckets ===" marker (i.e. training finished).
     *
     * @param  array<\SplFileInfo>  $files
     * @return Collection<string, string>
     */
    private function groupLatestPerPipeline(array $files): Collection
    {
        $grouped = collect();

        foreach ($files as $file) {
            $name = $file->getFilename();

            // e.g. "A-2026-07-25_10.log" → "A", "HID-2026-07-25_12.log" → "HID",
            //      "REALPICKS-2026-07-25_1054.log" → "REALPICKS"
            if (! preg_match('/^([A-Za-z0-9]+)-\d{4}-\d{2}-\d{2}/', $name, $m)) {
                continue;
            }

            // Skip in-progress logs that haven't reached the buckets section yet.
            $contents = File::get($file->getRealPath());
            if (! str_contains($contents, '=== Probability Buckets ===')) {
                continue;
            }

            $pipeline = strtoupper($m[1]);
            $existing = $grouped->get($pipeline);

            if ($existing === null || $file->getMTime() > filemtime($existing)) {
                $grouped->put($pipeline, $file->getRealPath());
            }
        }

        return $grouped;
    }

    /**
     * Parse the "=== Probability Buckets ===" section from a training log.
     *
     * @return array<int, array{bucket_label: string, bucket_min: ?float, bucket_max: ?float, rows: int, win_rate: float, avg_pnl: float, median_pnl: float}>
     */
    private function parseBuckets(string $filePath): array
    {
        $content = File::get($filePath);

        // Locate the bucket section between "=== Probability Buckets ==="
        // and the next blank line / "[labels]" marker / end-of-string.
        if (! preg_match(
            '/=== Probability Buckets ===\n.*?\n(.*?)(?:\n\n|\n\[|$)/s',
            $content,
            $sectionMatch
        )) {
            return [];
        }

        $lines = array_filter(explode("\n", $sectionMatch[1]));
        $rows = [];
        $headerSkipped = false;

        foreach ($lines as $line) {
            if (! $headerSkipped) {
                $headerSkipped = true;

                continue; // skip column-header line
            }

            $line = trim($line);

            // Line format: "(-0.001, 0.3]   134  0.104478 0.057053      -0.565"
            if (! preg_match(
                '/^\(([^]]+)\]\s+(\d+)\s+([\d.]+)\s+([\d.-]+)\s+([\d.-]+)/',
                $line,
                $m
            )) {
                continue;
            }

            $rangeParts = array_map('trim', explode(',', $m[1]));

            $rows[] = [
                'bucket_label' => "({$m[1]}]",
                'bucket_min' => isset($rangeParts[0]) ? (float) $rangeParts[0] : null,
                'bucket_max' => isset($rangeParts[1]) ? (float) $rangeParts[1] : null,
                'rows' => (int) $m[2],
                'win_rate' => (float) $m[3],
                'avg_pnl' => (float) $m[4],
                'median_pnl' => (float) $m[5],
            ];
        }

        return $rows;
    }

    /**
     * Resolve the version string for a pipeline.
     */
    private function resolveVersion(string $pipeline): string
    {
        // Single-letter pipeline: look up from config/app.php
        if (isset(self::PIPELINE_VERSION_KEYS[$pipeline])) {
            $version = config(self::PIPELINE_VERSION_KEYS[$pipeline], '');

            if ($version !== '' && $version !== null) {
                return $version;
            }
        }

        // Multi-letter combined pipeline (e.g. HID): concatenate constituent versions
        if (strlen($pipeline) > 1 && preg_match('/^[A-Z]+$/', $pipeline)) {
            $parts = [];
            foreach (str_split($pipeline) as $letter) {
                if (isset(self::PIPELINE_VERSION_KEYS[$letter])) {
                    $v = config(self::PIPELINE_VERSION_KEYS[$letter], '');
                    if ($v !== '' && $v !== null) {
                        $parts[] = $v;
                    }
                }
            }

            if ($parts !== []) {
                return implode('+', $parts);
            }
        }

        // Fallback for special names like "REALPICKS"
        return strtolower($pipeline);
    }
}
