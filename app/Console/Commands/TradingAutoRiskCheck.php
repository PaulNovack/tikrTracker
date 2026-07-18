<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs after market close and before market open to enforce automatic risk management:
 *
 * AFTER CLOSE (risk check):
 *   - If today's live P&L exceeds DAILY_LOSS_LIMIT → switch to paper trading
 *   - If CONSECUTIVE_LOSS_DAYS losing live days in a row → switch to paper trading
 *
 * BEFORE OPEN (resume check):
 *   - If currently in paper mode and yesterday's paper P&L was profitable → switch back to live trading
 *
 * Switching is done by rewriting .secret. Queue workers must be restarted manually.
 */
class TradingAutoRiskCheck extends Command
{
    protected $signature = 'trading:auto-risk-check
                            {--mode=auto : "risk" = after-close check, "resume" = pre-open check, "auto" = detect from time}
                            {--dry-run : Log what would happen without making changes}
                            {--force-live : Force immediate switch to live trading, bypassing resume check conditions}';

    protected $description = 'Auto risk management: switch to paper trading on bad days, resume live when paper is profitable';

    protected string $secretPath;

    public function __construct()
    {
        parent::__construct();
        $this->secretPath = base_path('.secret');
    }

    public function handle(): int
    {
        $mode = $this->option('mode');
        $isDryRun = (bool) $this->option('dry-run');

        if ($mode === 'auto') {
            $hour = (int) now()->timezone('America/New_York')->format('H');
            $mode = $hour >= 16 ? 'risk' : 'resume';
        }

        if ($isDryRun) {
            $this->warn('[DRY RUN] No changes will be made.');
        }

        if ($this->option('force-live')) {
            if ($this->isCurrentlyPaper()) {
                $this->switchToLive('Manually forced via --force-live flag.', $isDryRun);
            } else {
                $this->info('Already in live trading mode. Nothing to do.');
            }

            return 0;
        }

        if ($mode === 'risk') {
            return $this->runRiskCheck($isDryRun);
        }

        return $this->runResumeCheck($isDryRun);
    }

    /**
     * After market close: check if today's live trading hit loss thresholds.
     */
    protected function runRiskCheck(bool $isDryRun): int
    {
        $dailyLossLimit = TradingSettingService::getDailyLossLimit();
        $consecutiveLossDays = TradingSettingService::getConsecutiveLossDays();

        $this->info('Running after-close risk check...');

        // Already in paper mode — nothing to do
        if ($this->isCurrentlyPaper()) {
            $this->info('Already in paper trading mode. No risk check needed.');

            return 0;
        }

        // Calculate today's live P&L
        $todayPL = $this->calculateDayPL(today(), isPaper: false);
        $this->line("Today's live P&L: $".number_format($todayPL, 2));

        // Check daily loss limit
        if ($todayPL <= $dailyLossLimit) {
            $reason = "Daily loss limit hit: today's P&L \${$todayPL} ≤ limit \${$dailyLossLimit}";
            $this->warn("TRIGGER: {$reason}");
            $this->switchToPaper($reason, $isDryRun);

            return 0;
        }

        // Check consecutive loss days
        $streak = $this->getLiveLosingStreak();
        $this->line("Consecutive losing live days: {$streak}");

        if ($streak >= $consecutiveLossDays) {
            $reason = "Consecutive loss days hit: {$streak} losing days in a row (threshold: {$consecutiveLossDays})";
            $this->warn("TRIGGER: {$reason}");
            $this->switchToPaper($reason, $isDryRun);

            return 0;
        }

        $this->info('Risk check passed. Staying in live trading.');
        Log::channel('scheduled')->info('[AutoRisk] Risk check passed', [
            'today_pl' => $todayPL,
            'daily_loss_limit' => $dailyLossLimit,
            'losing_streak' => $streak,
            'consecutive_loss_days_threshold' => $consecutiveLossDays,
        ]);

        return 0;
    }

    /**
     * Before market open: if in paper mode and yesterday's paper P&L was profitable, switch back to live.
     */
    protected function runResumeCheck(bool $isDryRun): int
    {
        $this->info('Running pre-open resume check...');

        if (! $this->isCurrentlyPaper()) {
            $this->info('Already in live trading mode. No resume check needed.');

            return 0;
        }

        $yesterday = today()->subDay();
        $paperPL = $this->calculateDayPL($yesterday, isPaper: true);
        $this->line("Yesterday's paper P&L: $".number_format($paperPL, 2));

        $resumeMinProfit = TradingSettingService::getPaperResumeMinProfit();
        $this->line('Resume min profit threshold: $'.number_format($resumeMinProfit, 2));

        if ($paperPL >= $resumeMinProfit) {
            $reason = "Paper trading profitable yesterday: +\${$paperPL}. Switching back to live.";
            $this->info("RESUME: {$reason}");
            $this->switchToLive($reason, $isDryRun);
        } else {
            $this->info('Paper trading not yet profitable enough. Staying in paper mode.');
            Log::channel('scheduled')->info('[AutoRisk] Resume check: paper not yet profitable', [
                'yesterday_paper_pl' => $paperPL,
                'resume_min_profit' => $resumeMinProfit,
            ]);
        }

        return 0;
    }

    /**
     * Calculate closed P&L for a given day (live or paper).
     */
    protected function calculateDayPL(\Illuminate\Support\Carbon $date, bool $isPaper): float
    {
        $pairs = AlpacaOrder::query()
            ->selectRaw('(sell.filled_avg_price - buy.filled_avg_price) * sell.filled_qty AS pl')
            ->from('alpaca_orders AS sell')
            ->join('alpaca_orders AS buy', 'buy.alpaca_order_id', '=', 'sell.parent_alpaca_order_id')
            ->where('sell.side', 'sell')
            ->where('sell.order_type', 'stop')
            ->where('sell.status', 'filled')
            ->whereDate('sell.filled_at', $date)
            ->where('buy.is_paper', $isPaper)
            ->whereNotNull('sell.filled_avg_price')
            ->whereNotNull('buy.filled_avg_price')
            ->get();

        return (float) $pairs->sum('pl');
    }

    /**
     * Count how many consecutive losing live trading days precede today.
     */
    protected function getLiveLosingStreak(): int
    {
        $streak = 0;
        $date = today()->subDay();

        for ($i = 0; $i < 14; $i++) {
            if ($date->isWeekend()) {
                $date = $date->subDay();

                continue;
            }

            $pl = $this->calculateDayPL($date, isPaper: false);

            if ($pl < 0) {
                $streak++;
                $date = $date->subDay();
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Check whether .secret currently has paper trading active.
     */
    protected function isCurrentlyPaper(): bool
    {
        if (! file_exists($this->secretPath)) {
            return false;
        }

        $contents = file_get_contents($this->secretPath);

        return (bool) preg_match('/^ALPACA_PAPER_TRADING=true/m', $contents);
    }

    /**
     * Rewrite .secret to enable paper trading.
     */
    protected function switchToPaper(string $reason, bool $isDryRun): void
    {
        Log::channel('scheduled')->warning('[AutoRisk] Switching to PAPER trading', [
            'reason' => $reason,
            'action_required' => 'Restart queue workers: php artisan queue:restart',
        ]);

        $this->warn("[AutoRisk] Switching to PAPER trading: {$reason}");
        $this->warn('[AutoRisk] ACTION REQUIRED: Restart queue workers for new credentials to take effect.');

        if ($isDryRun) {
            return;
        }

        $contents = file_get_contents($this->secretPath);

        // Comment out PROD credentials
        $contents = preg_replace('/^(ALPACA_KEY_ID=AK[^\n]+)$/m', '#$1', $contents);
        $contents = preg_replace('/^(ALPACA_SECRET_KEY=Ad[^\n]+)$/m', '#$1', $contents);
        $contents = preg_replace('/^(ALPACA_PAPER_TRADING=false)$/m', '#$1', $contents);
        $contents = preg_replace('/^(ALPACA_API_KEY=AK[^\n]+)$/m', '#$1', $contents);
        $contents = preg_replace('/^(ALPACA_API_SECRET=Ad[^\n]+)$/m', '#$1', $contents);

        // Uncomment PAPER credentials
        $contents = preg_replace('/^#(ALPACA_KEY_ID=PK[^\n]+)$/m', '$1', $contents);
        $contents = preg_replace('/^#(ALPACA_SECRET_KEY=GJ[^\n]+)$/m', '$1', $contents);
        $contents = preg_replace('/^#(ALPACA_PAPER_TRADING=true)$/m', '$1', $contents);
        $contents = preg_replace('/^#(ALPACA_API_KEY=PK[^\n]+)$/m', '$1', $contents);
        $contents = preg_replace('/^#(ALPACA_API_SECRET=GJ[^\n]+)$/m', '$1', $contents);

        file_put_contents($this->secretPath, $contents);

        $this->info('✅ .secret updated to paper trading credentials.');
    }

    /**
     * Rewrite .secret to enable live trading.
     */
    protected function switchToLive(string $reason, bool $isDryRun): void
    {
        Log::channel('scheduled')->info('[AutoRisk] Switching back to LIVE trading', [
            'reason' => $reason,
            'action_required' => 'Restart queue workers: php artisan queue:restart',
        ]);

        $this->info("[AutoRisk] Switching back to LIVE trading: {$reason}");
        $this->warn('[AutoRisk] ACTION REQUIRED: Restart queue workers for new credentials to take effect.');

        if ($isDryRun) {
            return;
        }

        $contents = file_get_contents($this->secretPath);

        // Uncomment PROD credentials
        $contents = preg_replace('/^#(ALPACA_KEY_ID=AK[^\n]+)$/m', '$1', $contents);
        $contents = preg_replace('/^#(ALPACA_SECRET_KEY=Ad[^\n]+)$/m', '$1', $contents);
        $contents = preg_replace('/^#(ALPACA_PAPER_TRADING=false)$/m', '$1', $contents);
        $contents = preg_replace('/^#(ALPACA_API_KEY=AK[^\n]+)$/m', '$1', $contents);
        $contents = preg_replace('/^#(ALPACA_API_SECRET=Ad[^\n]+)$/m', '$1', $contents);

        // Comment out PAPER credentials
        $contents = preg_replace('/^(ALPACA_KEY_ID=PK[^\n]+)$/m', '#$1', $contents);
        $contents = preg_replace('/^(ALPACA_SECRET_KEY=GJ[^\n]+)$/m', '#$1', $contents);
        $contents = preg_replace('/^(ALPACA_PAPER_TRADING=true)$/m', '#$1', $contents);
        $contents = preg_replace('/^(ALPACA_API_KEY=PK[^\n]+)$/m', '#$1', $contents);
        $contents = preg_replace('/^(ALPACA_API_SECRET=GJ[^\n]+)$/m', '#$1', $contents);

        file_put_contents($this->secretPath, $contents);

        $this->info('✅ .secret updated to live trading credentials.');
    }
}
