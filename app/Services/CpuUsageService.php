<?php

namespace App\Services;

class CpuUsageService
{
    private ?array $previousStats = null;

    /**
     * Get current CPU usage percentage
     */
    public function getCurrentUsage(): float
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return $this->getLoadAverage();
        }

        $stats = $this->getCpuStats();

        if ($stats === null) {
            return $this->getLoadAverage();
        }

        // If we have previous stats, calculate the difference
        if ($this->previousStats !== null) {
            return $this->calculateUsageFromStats($this->previousStats, $stats);
        }

        // Store current stats and wait a moment for next reading
        $this->previousStats = $stats;
        usleep(100000); // 0.1 seconds
        $newStats = $this->getCpuStats();

        if ($newStats === null) {
            return $this->getLoadAverage();
        }

        return $this->calculateUsageFromStats($stats, $newStats);
    }

    /**
     * Get CPU usage as a percentage with a single measurement
     */
    public function getInstantUsage(): float
    {
        $firstStats = $this->getCpuStats();

        if ($firstStats === null) {
            return $this->getLoadAverage();
        }

        usleep(100000); // 0.1 seconds

        $secondStats = $this->getCpuStats();

        if ($secondStats === null) {
            return $this->getLoadAverage();
        }

        return $this->calculateUsageFromStats($firstStats, $secondStats);
    }

    /**
     * Get load average (alternative method)
     */
    public function getLoadAverage(): float
    {
        $load = sys_getloadavg();

        if ($load === false) {
            return 0.0;
        }

        // Return 1-minute load average as a percentage
        // Assuming number of CPU cores
        $cores = $this->getCpuCoreCount();

        return round(($load[0] / $cores) * 100, 2);
    }

    /**
     * Get the number of CPU cores
     */
    public function getCpuCoreCount(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 1;
        }

        $cpuinfo = @file_get_contents('/proc/cpuinfo');

        if ($cpuinfo === false) {
            return 1;
        }

        $processors = preg_match_all('/^processor/m', $cpuinfo);

        return max(1, $processors);
    }

    /**
     * Get detailed CPU information
     */
    public function getDetailedInfo(): array
    {
        $load = sys_getloadavg();

        return [
            'usage_percent' => $this->getCurrentUsage(),
            'load_average_1min' => $load[0] ?? 0,
            'load_average_5min' => $load[1] ?? 0,
            'load_average_15min' => $load[2] ?? 0,
            'cpu_cores' => $this->getCpuCoreCount(),
            'timestamp' => now(),
        ];
    }

    /**
     * Read CPU statistics from /proc/stat
     */
    private function getCpuStats(): ?array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        $stat = @file_get_contents('/proc/stat');

        if ($stat === false) {
            return null;
        }

        $lines = explode("\n", $stat);
        $cpuLine = null;

        foreach ($lines as $line) {
            if (strpos($line, 'cpu ') === 0) {
                $cpuLine = $line;
                break;
            }
        }

        if ($cpuLine === null) {
            return null;
        }

        $values = preg_split('/\s+/', trim($cpuLine));

        if (count($values) < 8) {
            return null;
        }

        // Return CPU time values
        // [0] = 'cpu', [1] = user, [2] = nice, [3] = system, [4] = idle,
        // [5] = iowait, [6] = irq, [7] = softirq
        return [
            'user' => (int) $values[1],
            'nice' => (int) $values[2],
            'system' => (int) $values[3],
            'idle' => (int) $values[4],
            'iowait' => (int) $values[5],
            'irq' => (int) $values[6],
            'softirq' => (int) $values[7],
        ];
    }

    /**
     * Calculate CPU usage percentage from two stat readings
     */
    private function calculateUsageFromStats(array $oldStats, array $newStats): float
    {
        $oldIdle = $oldStats['idle'] + $oldStats['iowait'];
        $newIdle = $newStats['idle'] + $newStats['iowait'];

        $oldTotal = array_sum($oldStats);
        $newTotal = array_sum($newStats);

        $totalDiff = $newTotal - $oldTotal;
        $idleDiff = $newIdle - $oldIdle;

        if ($totalDiff === 0) {
            return 0.0;
        }

        $usage = (($totalDiff - $idleDiff) / $totalDiff) * 100;

        return round(max(0, min(100, $usage)), 2);
    }

    /**
     * Check if CPU usage is above threshold
     */
    public function isHighUsage(float $threshold = 80.0): bool
    {
        return $this->getCurrentUsage() > $threshold;
    }

    /**
     * Get CPU usage via shell command (alternative method)
     */
    public function getUsageViaTop(): ?float
    {
        $output = shell_exec("top -bn1 | grep 'Cpu(s)' | sed 's/.*, *\\([0-9.]*\\)%* id.*/\\1/' | awk '{print 100 - $1}'");

        if ($output === null) {
            return null;
        }

        return round((float) trim($output), 2);
    }
}
