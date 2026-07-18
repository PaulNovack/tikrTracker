import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { AlertTriangle, RefreshCw, Thermometer } from 'lucide-react';

interface TemperatureReading {
    section: string;
    label: string;
    value: number;
    raw: string;
}

interface FanReading {
    section: string;
    label: string;
    value: number;
    raw: string;
}

interface SensorSection {
    name: string;
    adapter: string | null;
    temperature_readings: TemperatureReading[];
    fan_readings: FanReading[];
    other_readings: Array<{
        label: string;
        value: string;
    }>;
}

interface SensorSummary {
    highest_temperature: number | null;
    average_temperature: number | null;
    temperature_count: number;
    fan_count: number;
}

interface CpuTempResponse {
    content: string;
    sections: SensorSection[];
    temperatures: TemperatureReading[];
    fans: FanReading[];
    summary: SensorSummary;
    success: boolean;
    lastUpdated: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    { title: 'CPU Temp', href: '/logs/cpu-temp' },
];

function formatTemperature(temperature: number | null): string {
    if (temperature === null) {
        return '—';
    }

    return `${temperature.toFixed(1)}°C`;
}

function getTemperatureTone(temperature: number | null): string {
    if (temperature === null) {
        return 'text-gray-600 bg-gray-50 border-gray-200 dark:text-gray-400 dark:bg-gray-800 dark:border-gray-700';
    }

    if (temperature < 60) {
        return 'text-green-700 bg-green-50 border-green-200 dark:text-green-400 dark:bg-green-900/20 dark:border-green-800';
    }

    if (temperature < 75) {
        return 'text-yellow-700 bg-yellow-50 border-yellow-200 dark:text-yellow-400 dark:bg-yellow-900/20 dark:border-yellow-800';
    }

    return 'text-red-700 bg-red-50 border-red-200 dark:text-red-400 dark:bg-red-900/20 dark:border-red-800';
}

function getTemperatureFill(temperature: number): string {
    if (temperature < 60) {
        return 'bg-green-500';
    }

    if (temperature < 75) {
        return 'bg-yellow-500';
    }

    if (temperature < 85) {
        return 'bg-orange-500';
    }

    return 'bg-red-500';
}

function TemperatureBar({ label, temperature }: { label: string; temperature: number }) {
    const width = Math.max(0, Math.min(temperature, 100));
    const fillClass = getTemperatureFill(temperature);

    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between gap-3 text-sm">
                <span className="font-mono font-medium">{label}</span>
                <span className="font-mono text-xs text-muted-foreground">{temperature.toFixed(1)}°C</span>
            </div>
            <div className="h-4 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                <div
                    className={`flex h-full items-center justify-end pr-2 transition-all duration-300 ${fillClass}`}
                    style={{ width: `${width}%` }}
                >
                    {width > 12 && (
                        <span className="text-xs font-bold text-white">{temperature.toFixed(0)}°</span>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function CpuTemp() {
    const [content, setContent] = useState('');
    const [sections, setSections] = useState<SensorSection[]>([]);
    const [summary, setSummary] = useState<SensorSummary | null>(null);
    const [success, setSuccess] = useState(true);
    const [lastUpdated, setLastUpdated] = useState('');
    const [isLoading, setIsLoading] = useState(true);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const fetchCpuTemp = async () => {
        try {
            const response = await fetch('/api/logs/cpu-temp');
            const data: CpuTempResponse = await response.json();

            setContent(data.content || '');
            setSections(data.sections || []);
            setSummary(data.summary || null);
            setSuccess(data.success ?? true);
            setLastUpdated(data.lastUpdated || new Date().toLocaleString());
        } catch (error) {
            console.error('Error fetching CPU temperatures:', error);
            setSuccess(false);
        } finally {
            setIsLoading(false);
        }
    };

    useEffect(() => {
        fetchCpuTemp();

        if (autoRefresh) {
            intervalRef.current = setInterval(fetchCpuTemp, 10000);
        }

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [autoRefresh]);

    const highestTemperature = summary?.highest_temperature ?? null;
    const visibleSections = sections.filter((section) => ![
        'BAT1-acpi-0',
        'hp-isa-0000',
        'ucsi_source_psy_USBC000:001-isa-0000',
        'ucsi_source_psy_USBC000:002-isa-0000',
    ].includes(section.name));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="CPU Temp" />

            <div className="space-y-6 p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">System</p>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-white">CPU Temperature</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Live output from the <span className="font-mono">sensors</span> command.
                            {lastUpdated && <span className="ml-2">Last refreshed: {lastUpdated}</span>}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            onClick={() => setAutoRefresh(!autoRefresh)}
                            variant={autoRefresh ? 'default' : 'outline'}
                            size="sm"
                        >
                            <RefreshCw className={`mr-2 h-4 w-4 ${autoRefresh ? 'animate-spin' : ''}`} />
                            {autoRefresh ? 'Auto-refresh On' : 'Auto-refresh Off'}
                        </Button>
                        <Button
                            onClick={fetchCpuTemp}
                            variant="outline"
                            size="sm"
                        >
                            <RefreshCw className="mr-2 h-4 w-4" />
                            Refresh Now
                        </Button>
                    </div>
                </div>

                {!success && (
                    <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                        <div>
                            <p className="font-medium">Unable to read sensor data</p>
                            <p className="text-sm text-red-700 dark:text-red-300">
                                The command completed with an error. Showing the latest captured output below.
                            </p>
                        </div>
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <Card className={getTemperatureTone(highestTemperature)}>
                        <CardHeader className="pb-2">
                            <CardDescription>Highest Temperature</CardDescription>
                            <CardTitle className="flex items-center gap-2 text-2xl">
                                <Thermometer className="h-5 w-5" />
                                {formatTemperature(highestTemperature)}
                            </CardTitle>
                        </CardHeader>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Average Temperature</CardDescription>
                            <CardTitle className="text-2xl">
                                {formatTemperature(summary?.average_temperature ?? null)}
                            </CardTitle>
                        </CardHeader>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Temperature Readings</CardDescription>
                            <CardTitle className="text-2xl">{summary?.temperature_count ?? 0}</CardTitle>
                        </CardHeader>
                    </Card>

                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Temperature Bars</CardTitle>
                        <CardDescription>HTOP-style temperature bars derived from the sensors command.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {isLoading ? (
                            <div className="py-8 text-center text-sm text-muted-foreground">
                                Loading sensor data...
                            </div>
                        ) : visibleSections.length > 0 ? (
                            <div className="grid gap-4 lg:grid-cols-2">
                                {visibleSections.map((section, sectionIndex) => (
                                    <div key={`${section.name}-${sectionIndex}`} className="rounded-lg border border-border/70 bg-card p-4 shadow-sm">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <h3 className="text-sm font-semibold uppercase tracking-wide text-foreground">
                                                    {section.name}
                                                </h3>
                                                {section.adapter && (
                                                    <p className="text-xs text-muted-foreground">{section.adapter}</p>
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {section.temperature_readings.length} temps • {section.fan_readings.length} fans
                                            </div>
                                        </div>

                                        {section.temperature_readings.length > 0 && (
                                            <div className="mt-4 space-y-3">
                                                {section.temperature_readings.map((reading, readingIndex) => (
                                                    <TemperatureBar
                                                        key={`${reading.section}-${reading.label}-${readingIndex}`}
                                                        label={reading.label}
                                                        temperature={reading.value}
                                                    />
                                                ))}
                                            </div>
                                        )}

                                        {section.fan_readings.length > 0 && (
                                            <div className="mt-4 flex flex-wrap gap-2">
                                                {section.fan_readings.map((reading, readingIndex) => (
                                                    <div
                                                        key={`${reading.section}-${reading.label}-${readingIndex}`}
                                                        className="rounded-md border border-border/70 bg-muted/40 px-3 py-2 text-sm"
                                                    >
                                                        <div className="font-medium">{reading.label}</div>
                                                        <div className="font-mono text-base font-semibold">{reading.value} RPM</div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}

                                        {section.other_readings.length > 0 && (
                                            <p className="mt-4 text-xs text-muted-foreground">
                                                {section.other_readings.length} additional readings available in the raw output.
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="py-8 text-center text-sm text-muted-foreground">
                                No sensor sections were detected.
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Raw Sensors Output</CardTitle>
                        <CardDescription>Direct output from the shell command for troubleshooting.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <pre className="max-h-[520px] overflow-auto whitespace-pre-wrap rounded-lg bg-slate-950 p-4 font-mono text-xs leading-5 text-slate-100">
                            {content || 'No data available'}
                        </pre>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}