import React, { useMemo, useRef, useState } from 'react';

interface OHLCBar {
    time: string;
    open: number;
    high: number;
    low: number;
    close: number;
    volume: number;
}

interface CandlestickChartProps {
    data: OHLCBar[];
    height?: number;
    isPositive?: boolean;
    timeRange?: '1D' | 'Last Open Day' | '5D' | '1M' | '3M' | '6M' | '1Y' | 'MAX';
    hasEnoughHourlyData?: boolean;
}

function parseUtcDate(time: string): Date {
    if (time.includes('T')) {
        return new Date(time.endsWith('Z') ? time : `${time}Z`);
    }

    return new Date(`${time.replace(' ', 'T')}Z`);
}

function formatEasternDate(time: string, options: Intl.DateTimeFormatOptions): string {
    return parseUtcDate(time).toLocaleString('en-US', {
        timeZone: 'America/New_York',
        ...options,
    });
}

function shouldShowTimeLabel(
    timeRange: CandlestickChartProps['timeRange'],
    hasEnoughHourlyData: boolean | undefined,
): boolean {
    return (
        ['1D', 'Last Open Day', '5D'].includes(timeRange ?? '1D') ||
        (hasEnoughHourlyData && ['1M', '3M', '6M'].includes(timeRange ?? '1D'))
    );
}

export default function CandlestickChart({
    data,
    height = 400,
    isPositive = true,
    timeRange = '1D',
    hasEnoughHourlyData = false,
}: CandlestickChartProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const [hoveredBar, setHoveredBar] = useState<{
        bar: OHLCBar;
        x: number;
        y: number;
        label: string;
    } | null>(null);

    const chart = useMemo(() => {
        if (data.length === 0) {
            return null;
        }

        const chartHeight = height;
        const marginTop = 18;
        const marginRight = 28;
        const marginBottom = 36;
        const marginLeft = 54;
        const chartWidth = 1000;
        const innerWidth = chartWidth - marginLeft - marginRight;
        const innerHeight = chartHeight - marginTop - marginBottom;

        const prices = data.flatMap((bar) => [bar.open, bar.high, bar.low, bar.close]);
        const minPrice = Math.min(...prices);
        const maxPrice = Math.max(...prices);
        const range = maxPrice - minPrice || Math.max(maxPrice * 0.01, 1);
        const padding = range * 0.1;
        const domainMin = minPrice - padding;
        const domainMax = maxPrice + padding;

        const xForIndex = (index: number) =>
            marginLeft + (index + 0.5) * (innerWidth / data.length);

        const yForPrice = (price: number) => {
            const pct = (price - domainMin) / (domainMax - domainMin || 1);
            return marginTop + innerHeight - pct * innerHeight;
        };

        const candleWidth = Math.max(3, Math.min(10, (innerWidth / data.length) * 0.55));
        const yTicks = 5;
        const tickValues = Array.from({ length: yTicks + 1 }, (_, index) =>
            domainMin + ((domainMax - domainMin) * index) / yTicks,
        ).reverse();

        const xTickStep = Math.max(1, Math.ceil(data.length / 8));

        return {
            chartHeight,
            chartWidth,
            candleWidth,
            xForIndex,
            yForPrice,
            tickValues,
            xTickStep,
        };
    }, [data, height]);

    if (!chart) {
        return (
            <div className="flex items-center justify-center" style={{ height }}>
                <p className="text-gray-500 dark:text-gray-400">No data available</p>
            </div>
        );
    }

    const tooltipLeft = hoveredBar
        ? Math.min(
              hoveredBar.x + 16,
              Math.max(16, (containerRef.current?.clientWidth ?? chart.chartWidth) - 228),
          )
        : 0;
    const tooltipTop = hoveredBar ? Math.max(12, hoveredBar.y - 112) : 0;

    return (
        <div ref={containerRef} className="relative h-full w-full rounded-lg border bg-background">
            <div className="h-full w-full overflow-hidden">
                <svg
                    width="100%"
                    height={chart.chartHeight}
                    viewBox={`0 0 ${chart.chartWidth} ${chart.chartHeight}`}
                    preserveAspectRatio="none"
                    className="block"
                    role="img"
                    aria-label="Candlestick chart"
                >
                    <rect width={chart.chartWidth} height={chart.chartHeight} fill="transparent" />

                    {chart.tickValues.map((tickValue) => {
                        const y = chart.yForPrice(tickValue);
                        return (
                            <g key={tickValue}>
                                <line
                                    x1={54}
                                    x2={chart.chartWidth - 28}
                                    y1={y}
                                    y2={y}
                                    stroke="#e5e7eb"
                                    strokeDasharray="3 3"
                                    className="dark:stroke-gray-700"
                                />
                                <text
                                    x={46}
                                    y={y + 4}
                                    textAnchor="end"
                                    fontSize={11}
                                    fill="#6b7280"
                                    className="dark:fill-gray-400"
                                >
                                    ${tickValue.toFixed(2)}
                                </text>
                            </g>
                        );
                    })}

                    {data.map((bar, index) => {
                        const x = chart.xForIndex(index);
                        const openY = chart.yForPrice(bar.open);
                        const closeY = chart.yForPrice(bar.close);
                        const highY = chart.yForPrice(bar.high);
                        const lowY = chart.yForPrice(bar.low);
                        const topY = Math.min(openY, closeY);
                        const bodyHeight = Math.max(1, Math.abs(closeY - openY));
                        const bodyWidth = chart.candleWidth;
                        const wickColor = bar.close >= bar.open ? '#059669' : '#dc2626';
                        const bodyColor = bar.close >= bar.open ? '#10b981' : '#ef4444';
                        const label = formatEasternDate(bar.time, {
                            month: 'short',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                        });

                        return (
                            <g
                                key={`${bar.time}-${index}`}
                                onMouseEnter={(event) => {
                                    const rect = containerRef.current?.getBoundingClientRect();
                                    if (!rect) {
                                        return;
                                    }

                                    setHoveredBar({
                                        bar,
                                        x: event.clientX - rect.left,
                                        y: event.clientY - rect.top,
                                        label,
                                    });
                                }}
                                onMouseMove={(event) => {
                                    const rect = containerRef.current?.getBoundingClientRect();
                                    if (!rect) {
                                        return;
                                    }

                                    setHoveredBar((current) =>
                                        current
                                            ? {
                                                  ...current,
                                                  x: event.clientX - rect.left,
                                                  y: event.clientY - rect.top,
                                              }
                                            : {
                                                  bar,
                                                  x: event.clientX - rect.left,
                                                  y: event.clientY - rect.top,
                                                  label,
                                              },
                                    );
                                }}
                                onMouseLeave={() => setHoveredBar(null)}
                            >
                                <title>
                                    {`${label} ET\nOpen: ${bar.open.toFixed(2)} High: ${bar.high.toFixed(2)} Low: ${bar.low.toFixed(2)} Close: ${bar.close.toFixed(2)} Volume: ${bar.volume.toLocaleString()}`}
                                </title>
                                <line
                                    x1={x}
                                    x2={x}
                                    y1={highY}
                                    y2={lowY}
                                    stroke={wickColor}
                                    strokeWidth={1}
                                />
                                <rect
                                    x={x - bodyWidth / 2}
                                    y={topY}
                                    width={bodyWidth}
                                    height={bodyHeight}
                                    fill={bodyColor}
                                    stroke={wickColor}
                                    strokeWidth={1}
                                />
                            </g>
                        );
                    })}

                    {data.map((bar, index) => {
                        if (index % chart.xTickStep !== 0 && index !== data.length - 1) {
                            return null;
                        }

                        const x = chart.xForIndex(index);
                        const label = shouldShowTimeLabel(timeRange, hasEnoughHourlyData)
                            ? formatEasternDate(bar.time, {
                                  hour: 'numeric',
                                  minute: '2-digit',
                              })
                            : formatEasternDate(bar.time, {
                                  month: 'short',
                                  day: 'numeric',
                              });

                        return (
                            <text
                                key={`x-${bar.time}-${index}`}
                                x={x}
                                y={chart.chartHeight - 12}
                                textAnchor="middle"
                                fontSize={11}
                                fill="#6b7280"
                                className="dark:fill-gray-400"
                            >
                                {label}
                            </text>
                        );
                    })}

                    <text
                        x={16}
                        y={18}
                        fontSize={12}
                        fill={isPositive ? '#059669' : '#dc2626'}
                        className="font-medium"
                    >
                        OHLC
                    </text>
                </svg>
            </div>

            {hoveredBar && (
                <div
                    className="pointer-events-none absolute z-20 rounded-lg border border-gray-300 bg-white/95 px-3 py-2 shadow-xl dark:border-gray-700 dark:bg-gray-900/95"
                    style={{
                        left: tooltipLeft,
                        top: tooltipTop,
                    }}
                >
                    <div className="text-xs text-gray-600 dark:text-gray-400">
                        {hoveredBar.label} ET
                    </div>
                    <div className="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                        Open: {hoveredBar.bar.open.toFixed(2)}
                    </div>
                    <div className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        High: {hoveredBar.bar.high.toFixed(2)}
                    </div>
                    <div className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        Low: {hoveredBar.bar.low.toFixed(2)}
                    </div>
                    <div className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        Close: {hoveredBar.bar.close.toFixed(2)}
                    </div>
                    <div className="text-xs text-gray-600 dark:text-gray-400">
                        Volume: {hoveredBar.bar.volume.toLocaleString()}
                    </div>
                </div>
            )}
        </div>
    );
}