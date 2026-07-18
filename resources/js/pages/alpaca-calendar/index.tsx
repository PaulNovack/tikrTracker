import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, TrendingUp, TrendingDown, Calendar } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';

interface DayData {
    total_pl: number;
    trade_count: number;
    symbol_count: number;
    win_count: number;
    loss_count: number;
}

interface Summary {
    total_pl: number;
    winning_days: number;
    losing_days: number;
    trading_days: number;
}

type Mode = 'live' | 'paper' | 'all';

interface Props {
    dailyData: Record<string, DayData>;
    year: number;
    month: number;
    mode: Mode;
    summary: Summary;
}

const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Alpaca', href: '/alpaca-orders' },
    { title: 'P & L Calendar', href: '/alpaca-calendar' },
];

function formatPL(amount: number): string {
    const abs = Math.abs(amount);
    const formatted = `$${abs.toFixed(2)}`;
    return amount >= 0 ? `+${formatted}` : `-${formatted}`;
}

export default function AlpacaCalendar({ dailyData, year, month, mode, summary }: Props) {
    const daysInMonth = new Date(year, month, 0).getDate();
    const today = new Date();
    const isCurrentMonth = today.getFullYear() === year && today.getMonth() + 1 === month;

    function navigate(direction: 'prev' | 'next'): void {
        let newYear = year;
        let newMonth = direction === 'next' ? month + 1 : month - 1;
        if (newMonth > 12) { newMonth = 1; newYear += 1; }
        if (newMonth < 1) { newMonth = 12; newYear -= 1; }
        router.get('/alpaca-calendar', { year: newYear, month: newMonth, mode }, { preserveState: false });
    }

    function setMode(newMode: Mode): void {
        router.get('/alpaca-calendar', { year, month, mode: newMode }, { preserveState: false });
    }

    const totalPLPositive = summary.total_pl >= 0;    // Build weekday-only (Mon–Fri) grid cells
    // Each cell is { day: number } for a real day, or null for padding
    const weekdayCells: (number | null)[] = [];
    for (let d = 1; d <= daysInMonth; d++) {
        const dow = new Date(year, month - 1, d).getDay(); // 0=Sun,6=Sat
        if (dow === 0 || dow === 6) { continue; }
        // Monday = col 0 ... Friday = col 4
        const col = dow - 1;
        if (weekdayCells.length === 0) {
            // Pad the first week
            for (let i = 0; i < col; i++) { weekdayCells.push(null); }
        }
        weekdayCells.push(d);
    }
    // Pad to complete the last row
    while (weekdayCells.length % 5 !== 0) { weekdayCells.push(null); }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="P & L Calendar" />
            <div className="flex flex-col gap-4 p-6">
                {/* Calendar card */}
                <Card className="flex-1">
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-3 text-xl">
                                <Calendar className="h-5 w-5" />
                                {MONTH_NAMES[month - 1]} {year}
                                <span className={`text-lg font-bold ${summary.total_pl >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                    {formatPL(summary.total_pl)}
                                </span>
                                <span className="text-sm font-normal text-muted-foreground">|</span>
                                <span className="text-sm font-normal text-green-600 dark:text-green-400">{summary.winning_days} Winning Days</span>
                                <span className="text-sm font-normal text-muted-foreground">|</span>
                                <span className="text-sm font-normal text-red-600 dark:text-red-400">{summary.losing_days} Losing Days</span>
                                <span className="text-sm font-normal text-muted-foreground">|</span>
                                <span className="text-sm font-normal text-muted-foreground">
                                    {summary.trading_days > 0 ? Math.round((summary.winning_days / summary.trading_days) * 100) : 0}% Win Rate
                                </span>
                            </CardTitle>
                            <div className="flex items-center gap-3">
                                <div className="flex items-center rounded-md border">
                                    {(['live', 'paper', 'all'] as Mode[]).map((m) => (
                                        <Button
                                            key={m}
                                            variant={mode === m ? 'default' : 'ghost'}
                                            size="sm"
                                            className="rounded-none first:rounded-l-md last:rounded-r-md h-8 px-3 capitalize"
                                            onClick={() => setMode(m)}
                                        >
                                            {m}
                                        </Button>
                                    ))}
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button variant="outline" size="icon" onClick={() => navigate('prev')}>
                                        <ChevronLeft className="h-4 w-4" />
                                    </Button>
                                    {!isCurrentMonth && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get('/alpaca-calendar', { mode }, { preserveState: false })}
                                        >
                                            Today
                                        </Button>
                                    )}
                                    <Button variant="outline" size="icon" onClick={() => navigate('next')}>
                                        <ChevronRight className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {/* Day-of-week headers */}
                        <div className="grid grid-cols-5 gap-1 mb-1 max-w-[50%] mx-auto">
                            {DAY_NAMES.map((day) => (
                                <div
                                    key={day}
                                    className="text-center text-xs font-semibold text-muted-foreground py-2"
                                >
                                    {day}
                                </div>
                            ))}
                        </div>

                        {/* Calendar grid */}
                        <div className="grid grid-cols-5 gap-1 max-w-[50%] mx-auto">
                            {weekdayCells.map((day, idx) => {
                                if (day === null) {
                                    return <div key={`empty-${idx}`} className="aspect-square" />;
                                }

                                const dateKey = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                                const data = dailyData[dateKey] ?? null;
                                const isToday = isCurrentMonth && today.getDate() === day;

                                let bgClass = 'bg-muted/30 dark:bg-muted/10';
                                let plColor = '';

                                if (data) {
                                    if (data.total_pl > 0) {
                                        const intensity = Math.min(data.total_pl / 500, 1);
                                        bgClass = intensity > 0.5
                                            ? 'bg-green-200 dark:bg-green-900/60'
                                            : 'bg-green-100 dark:bg-green-900/30';
                                        plColor = 'text-green-700 dark:text-green-400';
                                    } else if (data.total_pl < 0) {
                                        const intensity = Math.min(Math.abs(data.total_pl) / 500, 1);
                                        bgClass = intensity > 0.5
                                            ? 'bg-red-200 dark:bg-red-900/60'
                                            : 'bg-red-100 dark:bg-red-900/30';
                                        plColor = 'text-red-700 dark:text-red-400';
                                    } else {
                                        bgClass = 'bg-muted/50 dark:bg-muted/20';
                                    }
                                }

                                return (
                                    <div
                                        key={dateKey}
                                        className={`
                                            aspect-square rounded-lg p-1.5 flex flex-col items-center justify-center
                                            border transition-colors
                                            ${bgClass}
                                            ${isToday ? 'ring-2 ring-primary border-primary' : 'border-border'}
                                            ${data ? 'cursor-default' : ''}
                                        `}
                                    >
                                        <span className={`text-xs font-medium leading-none mb-1.5 ${isToday ? 'text-primary font-bold' : 'text-muted-foreground'}`}>
                                            {day}
                                        </span>
                                        {data ? (
                                            <>
                                                <span className={`text-xs font-bold leading-none mb-1 ${plColor}`}>
                                                    {formatPL(data.total_pl)}
                                                </span>
                                                <span className="text-xs text-muted-foreground leading-none">
                                                    {data.symbol_count} Trades
                                                </span>
                                            </>
                                        ) : (
                                            <span className="text-[10px] text-muted-foreground/50">No Trades</span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>

                        {/* Legend */}
                        <div className="flex items-center justify-end gap-4 mt-4 text-xs text-muted-foreground">
                            <div className="flex items-center gap-1">
                                <TrendingUp className="h-3 w-3 text-green-600" />
                                <span>Profit</span>
                            </div>
                            <div className="flex items-center gap-1">
                                <TrendingDown className="h-3 w-3 text-red-600" />
                                <span>Loss</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
