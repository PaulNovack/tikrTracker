<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReassignDuplicateJTradeAlertsToX extends Command
{
    protected $signature = 'trade:reassign-duplicate-j-alerts-to-x
        {--date= : Trading date to clean up (EST) in YYYY-mm-dd format; defaults to today}
        {--dry-run : Preview rows that would be moved without updating the database}';

    protected $description = 'Move duplicate J trade alerts for a trading day to pipeline X while keeping alerts linked to Alpaca orders in J.';

    public function handle(): int
    {
        try {
            $targetDate = $this->resolveTargetDate();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $alerts = $this->loadTradeAlerts($targetDate);

        if ($alerts->isEmpty()) {
            $this->info("No pipeline J alerts found for {$targetDate}.");

            return self::SUCCESS;
        }

        $orderCounts = $this->loadOrderCounts($alerts->pluck('id'));
        [$moveIds, $groupSummaries, $protectedCount] = $this->identifyRowsToMove($alerts, $orderCounts);

        $this->info("Pipeline J alerts for {$targetDate}: {$alerts->count()} row(s) across ".count($groupSummaries).' minute bucket(s).');
        $this->line('Alerts linked to Alpaca orders and left in J: '.$protectedCount);

        if ($moveIds === []) {
            if ($protectedCount > 0) {
                $this->warn('Only duplicate rows linked to Alpaca orders were found; leaving them in J.');
            } else {
                $this->info('No duplicate rows needed reassignment.');
            }

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->warn('Dry run enabled. No rows were updated.');
            $this->line('Would move '.count($moveIds).' duplicate alert(s) from J to X.');

            return self::SUCCESS;
        }

        DB::table('trade_alerts')
            ->whereIn('id', $moveIds)
            ->update([
                'pipeline_run' => 'X',
                'updated_at' => now(),
            ]);

        $this->info('Moved '.count($moveIds).' duplicate alert(s) from J to X.');

        return self::SUCCESS;
    }

    private function resolveTargetDate(): string
    {
        $rawDate = trim((string) $this->option('date'));

        if ($rawDate === '') {
            return now('America/New_York')->toDateString();
        }

        return Carbon::parse($rawDate, 'America/New_York')->toDateString();
    }

    private function loadTradeAlerts(string $targetDate): Collection
    {
        return DB::table('trade_alerts')
            ->select([
                'id',
                'symbol',
                'asset_type',
                'signal_ts_est',
                'entry_ts_est',
                'pipeline_run',
            ])
            ->where('pipeline_run', 'J')
            ->whereDate('trading_date_est', $targetDate)
            ->orderBy('symbol')
            ->orderBy('asset_type')
            ->orderBy('entry_ts_est')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, object>  $alerts
     * @return array{0:list<int>,1:array<int,array<string,mixed>>,2:int}
     */
    private function identifyRowsToMove(Collection $alerts, array $orderCounts): array
    {
        $moveIds = [];
        $groupSummaries = [];
        $protectedCount = 0;

        $grouped = $alerts->groupBy(fn (object $alert): string => $this->buildMinuteGroupKey($alert));

        foreach ($grouped as $groupKey => $group) {
            $protectedRows = $group->filter(function (object $alert) use ($orderCounts): bool {
                return (int) ($orderCounts[(int) $alert->id] ?? 0) > 0;
            });

            $protectedIds = $protectedRows->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $protectedCount += count($protectedIds);

            if ($protectedRows->isNotEmpty()) {
                $rowsToMove = $group->reject(function (object $alert) use ($orderCounts): bool {
                    return (int) ($orderCounts[(int) $alert->id] ?? 0) > 0;
                });
            } else {
                $rowsToMove = $group->skip(1);
            }

            $moveIds = array_merge($moveIds, $rowsToMove->pluck('id')->map(fn ($id): int => (int) $id)->all());

            $groupSummaries[] = [
                'group_key' => $groupKey,
                'kept_ids' => $protectedIds !== [] ? $protectedIds : [(int) $group->first()->id],
                'moved_ids' => $rowsToMove->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'has_orders' => $protectedRows->isNotEmpty(),
            ];
        }

        return [array_values(array_unique($moveIds)), $groupSummaries, $protectedCount];
    }

    private function loadOrderCounts(Collection $alertIds): array
    {
        if ($alertIds->isEmpty()) {
            return [];
        }

        return DB::table('alpaca_orders')
            ->selectRaw('trade_alert_id, COUNT(*) as order_count')
            ->whereIn('trade_alert_id', $alertIds->all())
            ->groupBy('trade_alert_id')
            ->pluck('order_count', 'trade_alert_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    private function buildMinuteGroupKey(object $alert): string
    {
        $minuteBucketSource = (string) ($alert->signal_ts_est ?? $alert->entry_ts_est);

        $minuteBucket = Carbon::parse($minuteBucketSource, 'America/New_York')
            ->startOfMinute()
            ->format('Y-m-d H:i:00');

        return implode('|', [
            strtoupper((string) $alert->asset_type),
            strtoupper((string) $alert->symbol),
            $minuteBucket,
        ]);
    }
}
