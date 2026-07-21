import React, { useState, useEffect } from 'react';
import { Head, Form, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import Heading from '@/components/heading';
import { TrendingUp, Calendar } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { show as showAsset } from '@/routes/asset-info';

interface ScanResult {
    id: number;
    symbol: string;
    score: number;
    last_price: number;
    pct_60m: number;
    pct_30m: number;
    pct_15m: number;
    volume_boost: number;
    dist_from_vwap_pct: number;
    atr_like: number;
    avg_dollar_volume: number;
    topping_pattern: boolean;
    reasons: string[];
    vwap: number;
}

interface ScanMeta {
    as_of: string;
    asset_type: string;
    min_score: number;
    candidates_found: number;
    results_count: number;
    message?: string;
    data_window?: {
        start_60m: string;
        start_3h: string;
        end: string;
    };
}

interface ScanResponse {
    results: ScanResult[];
    meta: ScanMeta;
}

export default function HybridMomentumScan({ 
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
            <Head title="Hybrid Momentum Scan" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex items-center gap-3">
                    <TrendingUp className="h-8 w-8 text-blue-600" />
                    <Heading
                        title="Hybrid Momentum Scan"
                        description="Multi-timeframe momentum analysis with volume, VWAP, and volatility scoring"
                    />
                </div>

                {/* Scan Controls */}
                <Card>
                    <CardHeader>
                        <CardTitle>Scan Parameters</CardTitle>
                        <CardDescription>Configure your momentum scan settings</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form method="post" action="/hybrid-momentum-scan/scan">
                            <div className="space-y-6">
                                {/* Time Selection */}
                                <div>
                                    <div className="flex items-center gap-2 mb-4">
                                        <Calendar className="h-5 w-5 text-indigo-600" />
                                        <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Scan Time
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
                                                className="text-indigo-600 focus:ring-indigo-500"
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
                                                className="text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <span className="text-sm text-gray-700 dark:text-gray-300">
                                                Select specific date and time (EST)
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
                                                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:text-white"
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
                                                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:text-white"
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

                                {/* Original Parameters */}
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label htmlFor="asset_type" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Asset Type
                                    </label>
                                    <select 
                                        name="asset_type" 
                                        id="asset_type"
                                        defaultValue="stock"
                                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:text-white"
                                    >
                                        <option value="stock">Stocks</option>
                                        <option value="etf">ETFs</option>
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor="min_score" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Minimum Score
                                    </label>
                                    <select 
                                        name="min_score" 
                                        id="min_score"
                                        defaultValue="5"
                                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:text-white"
                                    >
                                        <option value="3">3 (More results)</option>
                                        <option value="4">4</option>
                                        <option value="5">5 (Balanced)</option>
                                        <option value="6">6</option>
                                        <option value="7">7 (High quality)</option>
                                        <option value="8">8 (Very selective)</option>
                                    </select>
                                </div>

                                <div className="flex items-end">
                                    <button
                                        type="submit"
                                        className="w-full bg-indigo-600 hover:bg-indigo-700 disabled:bg-gray-400 text-white font-medium py-2 px-4 rounded-md transition duration-150 ease-in-out"
                                    >
                                        Run Scan
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
                            <CardTitle>Scan Results</CardTitle>
                            <CardDescription>
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                    <div>
                                        <span className="font-medium">As of:</span><br />
                                        {new Date(scanResults.meta.as_of).toLocaleString()}
                                    </div>
                                    <div>
                                        <span className="font-medium">Candidates:</span><br />
                                        {scanResults.meta.candidates_found}
                                    </div>
                                    <div>
                                        <span className="font-medium">Results:</span><br />
                                        {scanResults.meta.results_count}
                                    </div>
                                    <div>
                                        <span className="font-medium">Min Score:</span><br />
                                        {scanResults.meta.min_score}
                                    </div>
                                </div>
                                {scanResults.meta.message && (
                                    <div className="mt-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-md">
                                        <p className="text-yellow-800 dark:text-yellow-200">{scanResults.meta.message}</p>
                                    </div>
                                )}
                            </CardDescription>
                        </CardHeader>

                        {/* Results Table */}
                        <CardContent>
                            {scanResults.results.length > 0 ? (
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
                                                    60m%
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    30m%
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    15m%
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Vol Boost
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    vs VWAP
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Avg Vol
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Reasons
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            {scanResults.results.map((result, index) => (
                                                <tr key={result.symbol} className={index % 2 === 0 ? 'bg-gray-50 dark:bg-gray-750' : ''}>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex items-center">
                                                            <Link
                                                                href={showAsset.url(result.id)}
                                                                className="text-sm font-medium text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                            >
                                                                {result.symbol}
                                                            </Link>
                                                            {result.topping_pattern && (
                                                                <span className="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                                                    Topping
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                            result.score >= 7 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                                                            result.score >= 5 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                                                            'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'
                                                        }`}>
                                                            {result.score.toFixed(1)}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                                        {formatPrice(result.last_price)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        {formatPercent(result.pct_60m)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        {formatPercent(result.pct_30m)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        {formatPercent(result.pct_15m)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        <span className={result.volume_boost > 0.5 ? 'text-green-600 dark:text-green-400' : 'text-gray-600 dark:text-gray-400'}>
                                                            {(result.volume_boost * 100).toFixed(0)}%
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        {formatPercent(result.dist_from_vwap_pct)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                                        {formatVolume(result.avg_dollar_volume)}
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="max-w-xs">
                                                            {result.reasons.map((reason, idx) => (
                                                                <span
                                                                    key={idx}
                                                                    className="inline-block mr-1 mb-1 px-2 py-1 text-xs bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded"
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
                                    No results found. Try adjusting the minimum score or check market hours.
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}