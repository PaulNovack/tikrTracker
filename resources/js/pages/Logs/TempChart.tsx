import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { RefreshCw, Thermometer } from 'lucide-react';
import {
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    Legend,
} from 'recharts';

interface TempDataPoint {
    time: string;
    temperature: number;
}

interface TempSeries {
    name: string;
    data: TempDataPoint[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    { title: 'Temp Chart', href: '/logs/temp-chart' },
];

const COLORS = [
    '#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4',
    '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6', '#f43f5e',
    '#84cc16', '#6366f1', '#d946ef', '#0ea5e9', '#a855f7',
];

function getColor(index: number): string {
    return COLORS[index % COLORS.length];
}

export default function TempChart() {
    const [series, setSeries] = useState<TempSeries[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const intervalRef = useRef<NodeJS.Timeout | null>(null);

    const fetchData = async () => {
        try {
            const response = await fetch('/api/logs/temp-chart');
            const data = await response.json();
            setSeries(data.series || []);
            setIsLoading(false);
        } catch (error) {
            console.error('Error fetching temp chart data:', error);
            setIsLoading(false);
        }
    };

    useEffect(() => {
        fetchData();

        if (autoRefresh) {
            intervalRef.current = setInterval(fetchData, 60_000); // Refresh every 60 seconds
        }

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [autoRefresh]);

    // Find the global time range across all series
    const allTimes = series.flatMap((s) => s.data.map((d) => new Date(d.time).getTime()));
    const minTime = allTimes.length > 0 ? Math.min(...allTimes) : Date.now() - 8 * 60 * 60 * 1000;
    const maxTime = allTimes.length > 0 ? Math.max(...allTimes) : Date.now();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Temp Chart" />
            <div className="space-y-4">
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="flex items-center gap-2">
                                    <Thermometer className="h-5 w-5 text-red-500" />
                                    Temperature Chart — Last 8 Hours
                                </CardTitle>
                                <CardDescription>
                                    All sensor readings from cpu_temperature_readings
                                    {autoRefresh && ' (Auto-refreshing every 60 seconds)'}
                                </CardDescription>
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    onClick={() => setAutoRefresh(!autoRefresh)}
                                    variant={autoRefresh ? 'default' : 'outline'}
                                    size="sm"
                                >
                                    <RefreshCw className={`h-4 w-4 mr-2 ${autoRefresh ? 'animate-spin' : ''}`} />
                                    {autoRefresh ? 'Auto-refresh ON' : 'Auto-refresh OFF'}
                                </Button>
                                <Button onClick={fetchData} variant="outline" size="sm">
                                    <RefreshCw className="h-4 w-4 mr-2" />
                                    Refresh Now
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {isLoading ? (
                            <div className="text-center py-8 text-muted-foreground">
                                Loading temperature data...
                            </div>
                        ) : series.length > 0 ? (
                            <ResponsiveContainer width="100%" height={500}>
                                <LineChart
                                    margin={{ top: 5, right: 20, left: 10, bottom: 5 }}
                                >
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted/30" />
                                    <XAxis
                                        dataKey="time"
                                        type="number"
                                        domain={[minTime, maxTime]}
                                        tickFormatter={(ts: number) =>
                                            new Date(ts).toLocaleTimeString('en-US', {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })
                                        }
                                        tick={{ fontSize: 11 }}
                                        scale="time"
                                    />
                                    <YAxis
                                        tickFormatter={(v: number) => `${v.toFixed(0)}°C`}
                                        tick={{ fontSize: 11 }}
                                        domain={['auto', 'auto']}
                                    />
                                    <Tooltip
                                        labelFormatter={(ts: number) =>
                                            new Date(ts).toLocaleString('en-US', {
                                                month: 'short',
                                                day: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit',
                                                second: '2-digit',
                                            })
                                        }
                                        formatter={(value: number, name: string) => [
                                            `${value.toFixed(1)}°C`,
                                            name,
                                        ]}
                                    />
                                    <Legend />
                                    {series.map((s, i) => (
                                        <Line
                                            key={s.name}
                                            data={s.data.map((d) => ({
                                                ...d,
                                                time: new Date(d.time).getTime(),
                                            }))}
                                            dataKey="temperature"
                                            name={s.name}
                                            stroke={getColor(i)}
                                            dot={false}
                                            strokeWidth={1.5}
                                            connectNulls
                                        />
                                    ))}
                                </LineChart>
                            </ResponsiveContainer>
                        ) : (
                            <div className="text-center py-8 text-muted-foreground">
                                No temperature data available for the last 8 hours
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
