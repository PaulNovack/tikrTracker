import React, { useState, useEffect } from 'react';
import { Head, Form, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import Heading from '@/components/heading';
import { Target, Calendar, Clock } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { show as showAsset } from '@/routes/asset-info';

// Helper function to calculate effective time based on lookback
const getEffectiveTime = (date: string, time: string, lookbackMinutes: number): string => {
    const datetime = new Date(`${date}T${time}:00`);
    const effectiveTime = new Date(datetime.getTime() - (lookbackMinutes * 60 * 1000));
    return effectiveTime.toTimeString().slice(0, 5); // HH:MM format
};

interface ScanMetrics {
    last: number;
    rangePct: number;
    pullbackPct: number;
    volumeSurge: number;
    vwap: number;
    ma5: number;
    ma20: number;
    // Legacy fields for compatibility
    lastOpen?: number;
    isGreen?: boolean;
    reclaimStrong?: boolean;
    chgPctLastBar?: number;
    distToRecentHighPct?: number;
    volSurge?: number;
    vw?: number;
    distAboveVwPct?: number;
    baseRangePct?: number;
    upperWickPct?: number;
    rsi14?: number;
    higherLows?: boolean | null;
}

interface ScanSignal {
    symbol: string;
    score: number;
    last: number;
    rangePct: number;
    pullbackPct: number;
    volumeSurge: number;
    vwap: number;
    ma5: number;
    ma20: number;
    reasons: string[];
    timestamp: string;
    // Legacy fields for compatibility
    asset_id?: number;
    asset_type?: string;
    endET?: string;
    metrics?: ScanMetrics;
}

interface ScanResponse {
    ok: boolean;
    isOptimalTime?: boolean;
    optimalTime?: string;
    asOfEst?: string;
    lookbackMinutes?: number;
    totalSymbols?: number;
    qualifiedSignals?: number;
    signals: ScanSignal[];
    // Legacy fields for compatibility
    inWindow?: boolean;
    windowET?: string;
    endET?: string;
    table?: string;
    asset_type?: string;
    startUtc?: string;
    endUtc?: string;
    minScore?: number;
    minBars?: number;
    minNotional?: number;
    signalCount?: number;
    message?: string;
    debug?: any;
}

export default function BuyWindow({ 
    scanResults 
}: { 
    scanResults?: ScanResponse 
}) {
    const [useCurrentTime, setUseCurrentTime] = useState(true);
    const [selectedDate, setSelectedDate] = useState('');
    const [selectedTime, setSelectedTime] = useState('');

    // Initialize with current EST time
    useEffect(() => {
        const now = new Date();
        // Convert to EST (UTC-5)
        const estOffset = -5 * 60; // EST is UTC-5
        const estTime = new Date(now.getTime() + (estOffset * 60 * 1000));
        
        setSelectedDate(estTime.toISOString().split('T')[0]); // YYYY-MM-DD
        setSelectedTime(estTime.toISOString().split('T')[1].slice(0, 5)); // HH:MM
    }, []);

    const formatPrice = (price: number) => `$${price.toFixed(2)}`;
    const formatPercent = (pct: number) => {
        const sign = pct >= 0 ? '+' : '';
        const color = pct >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
        return <span className={color}>{sign}{pct.toFixed(2)}%</span>;
    };
    const formatVolume = (vol: number) => {
        if (vol >= 1_000_000) return `$${(vol / 1_000_000).toFixed(1)}M`;
        if (vol >= 1_000) return `$${(vol / 1_000).toFixed(0)}K`;
        return `$${vol.toFixed(0)}`;
    };

    return (
        <AppLayout>
            <Head title="Buy Window Scanner" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex items-center gap-3">
                    <Target className="h-8 w-8 text-green-600" />
                    <Heading
                        title="Buy Window Scanner"
                        description="Optimal 11.533% algorithm - Best timing: 10:15 AM EST"
                    />
                </div>

                {/* Scan Controls */}
                <Card>
                    <CardHeader>
                        <CardTitle>Scanner Parameters</CardTitle>
                        <CardDescription>Configure your buy window scan settings</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form method="post" action="/buy-window/scan">
                            <div className="space-y-6">
                                {/* Time Selection */}
                                <div>
                                    <div className="flex items-center gap-2 mb-4">
                                        <Calendar className="h-5 w-5 text-green-600" />
                                        <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Scan Time (EST)
                                        </label>
                                    </div>
                                    
                                    <div className="space-y-4">
                                        {/* Current Time Option */}
                                        <label className="flex items-center gap-2 cursor-pointer">
                                            <input
                                                type="radio"
                                                name="time_mode"
                                                value="current"
                                                checked={useCurrentTime}
                                                onChange={() => setUseCurrentTime(true)}
                                                className="text-green-600 focus:ring-green-500"
                                            />
                                            <span className="text-sm text-gray-700 dark:text-gray-300">
                                                Use current time (default)
                                            </span>
                                        </label>

                                        {/* Custom Time Option */}
                                        <label className="flex items-center gap-2 cursor-pointer">
                                            <input
                                                type="radio"
                                                name="time_mode"
                                                value="custom"
                                                checked={!useCurrentTime}
                                                onChange={() => setUseCurrentTime(false)}
                                                className="text-green-600 focus:ring-green-500"
                                            />
                                            <span className="text-sm text-gray-700 dark:text-gray-300">
                                                Backtest with historical data (EST)
                                            </span>
                                        </label>

                                        {/* Custom DateTime Inputs */}
                                        {!useCurrentTime && (
                                            <div className="grid grid-cols-2 gap-4 ml-6">
                                                <div>
                                                    <label htmlFor="scan_date" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        Date (EST)
                                                    </label>
                                                    <input
                                                        type="date"
                                                        id="scan_date"
                                                        value={selectedDate}
                                                        onChange={(e) => setSelectedDate(e.target.value)}
                                                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white"
                                                    />
                                                </div>
                                                <div>
                                                    <label htmlFor="scan_time" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        Time (EST)
                                                    </label>
                                                    <input
                                                        type="time"
                                                        id="scan_time"
                                                        value={selectedTime}
                                                        onChange={(e) => setSelectedTime(e.target.value)}
                                                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white"
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                    {/* Hidden input for backend */}
                                    {!useCurrentTime && (
                                        <input
                                            type="hidden"
                                            name="as_of_est"
                                            value={selectedDate && selectedTime ? `${selectedDate} ${selectedTime}:00` : ''}
                                        />
                                    )}
                                </div>

                                {/* Scan Parameters */}
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                    <div>
                                        <label htmlFor="asset_type" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Asset Type
                                        </label>
                                        <select 
                                            name="asset_type" 
                                            id="asset_type"
                                            defaultValue="stock"
                                            className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option value="stock">Stocks</option>
</select>
                                    </div>

                                    <div>
                                        <label htmlFor="min_score" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Minimum Score
                                        </label>
                                        <select 
                                            name="min_score" 
                                            id="min_score"
                                            defaultValue="6"
                                            className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option value="4">4 (More results)</option>
                                            <option value="5">5</option>
                                            <option value="6">6 (Balanced)</option>
                                            <option value="7">7</option>
                                            <option value="8">8 (High quality)</option>
                                            <option value="9">9 (Very selective)</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label htmlFor="lookback" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Lookback {!useCurrentTime && selectedDate && selectedTime && "- Effective Time"}
                                        </label>
                                        <select 
                                            name="lookback" 
                                            id="lookback"
                                            defaultValue="150"
                                            className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option value="30">{useCurrentTime || !selectedDate || !selectedTime ? "30 min" : `30 min - ${getEffectiveTime(selectedDate, selectedTime, 30)}`}</option>
                                            <option value="60">{useCurrentTime || !selectedDate || !selectedTime ? "1 hour" : `1 hour - ${getEffectiveTime(selectedDate, selectedTime, 60)}`}</option>
                                            <option value="90">{useCurrentTime || !selectedDate || !selectedTime ? "1.5 hours" : `1.5 hours - ${getEffectiveTime(selectedDate, selectedTime, 90)}`}</option>
                                            <option value="120">{useCurrentTime || !selectedDate || !selectedTime ? "2 hours" : `2 hours - ${getEffectiveTime(selectedDate, selectedTime, 120)}`}</option>
                                            <option value="150">{useCurrentTime || !selectedDate || !selectedTime ? "2.5 hours" : `2.5 hours - ${getEffectiveTime(selectedDate, selectedTime, 150)}`}</option>
                                            <option value="180">{useCurrentTime || !selectedDate || !selectedTime ? "3 hours" : `3 hours - ${getEffectiveTime(selectedDate, selectedTime, 180)}`}</option>
                                            <option value="210">{useCurrentTime || !selectedDate || !selectedTime ? "3.5 hours" : `3.5 hours - ${getEffectiveTime(selectedDate, selectedTime, 210)}`}</option>
                                            <option value="240">{useCurrentTime || !selectedDate || !selectedTime ? "4 hours" : `4 hours - ${getEffectiveTime(selectedDate, selectedTime, 240)}`}</option>
                                            <option value="270">{useCurrentTime || !selectedDate || !selectedTime ? "4.5 hours" : `4.5 hours - ${getEffectiveTime(selectedDate, selectedTime, 270)}`}</option>
                                            <option value="300">{useCurrentTime || !selectedDate || !selectedTime ? "5 hours" : `5 hours - ${getEffectiveTime(selectedDate, selectedTime, 300)}`}</option>
                                        </select>
                                    </div>

                                    <div className="flex items-end">
                                        <button
                                            type="submit"
                                            className="w-full bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white font-medium py-2 px-4 rounded-md transition duration-150 ease-in-out"
                                        >
                                            Run Scanner
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </Form>
                    </CardContent>
                </Card>

                {/* Results */}
                {scanResults && (
                    <Card>
                        {/* Meta Information */}
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Clock className="h-5 w-5" />
                                Scanner Results
                            </CardTitle>
                            <CardDescription>
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                    <div>
                                        <span className="font-medium">Scan Time:</span><br />
                                        {new Date(scanResults.asOfEst || scanResults.endET || '').toLocaleString()}
                                    </div>
                                    <div>
                                        <span className="font-medium">Window:</span><br />
                                        {scanResults.windowET}
                                    </div>
                                    <div>
                                        <span className="font-medium">In Window:</span><br />
                                        <span className={scanResults.inWindow ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'}>
                                            {scanResults.inWindow ? 'Yes' : 'No'}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="font-medium">Signals:</span><br />
                                        {scanResults.signalCount ?? scanResults.signals.length}
                                    </div>
                                </div>
                                {scanResults.message && (
                                    <div className={`mt-4 p-3 border rounded-md ${
                                        scanResults.inWindow 
                                            ? 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800 text-yellow-800 dark:text-yellow-200'
                                            : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200'
                                    }`}>
                                        <p>{scanResults.message}</p>
                                    </div>
                                )}
                            </CardDescription>
                        </CardHeader>

                        {/* Results Table */}
                        <CardContent>
                            {scanResults.signals.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead className="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Symbol
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Score
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Price
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Range %
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Vol Surge
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Pullback %
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    VWAP
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Reasons
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            {scanResults.signals.map((signal, index) => (
                                                <tr key={signal.symbol} className={index % 2 === 0 ? 'bg-gray-50 dark:bg-gray-750' : ''}>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        {signal.asset_id ? (
                                                            <Link
                                                                href={showAsset.url(signal.asset_id)}
                                                                className="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 hover:underline"
                                                            >
                                                                {signal.symbol}
                                                            </Link>
                                                        ) : (
                                                            <span className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                                {signal.symbol}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                            signal.score >= 8 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                                                            signal.score >= 6 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                                                            'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'
                                                        }`}>
                                                            {signal.score}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                                        {formatPrice(signal.last)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        {formatPercent(signal.rangePct)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        <span className={signal.volumeSurge > 2.0 ? 'text-green-600 dark:text-green-400 font-semibold' : 'text-gray-600 dark:text-gray-400'}>
                                                            {signal.volumeSurge.toFixed(1)}x
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        <span className={signal.pullbackPct > -5 ? 'text-green-600 dark:text-green-400 font-semibold' : 'text-gray-600 dark:text-gray-400'}>
                                                            {signal.pullbackPct.toFixed(1)}%
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                                        {formatPrice(signal.vwap)}
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="max-w-xs">
                                                            {signal.reasons.map((reason, idx) => (
                                                                <span
                                                                    key={idx}
                                                                    className="inline-block mr-1 mb-1 px-2 py-1 text-xs bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 rounded"
                                                                >
                                                                    {reason}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="p-6 text-center text-gray-500 dark:text-gray-400">
                                    {scanResults.inWindow 
                                        ? 'No signals found with current parameters. Try adjusting the minimum score or lookback period.'
                                        : 'Scanner is only active during buy window (10:00-11:30 AM ET). No signals outside this window.'
                                    }
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}