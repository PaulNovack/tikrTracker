import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { show as showAsset } from '@/routes/asset-info';
import { Head } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';

interface AnalysisResult {
    id: number;
    symbol: string;
    name: string;
    type: 'stock';
    recommendation: string;
    score: number;
    price: number;
    rsi: number | null;
    threeHourChange: number | null;
    fiveHourChange: number | null;
    signals: string[];
}

interface Summary {
    totalAssets: number;
    cryptoCount: number;
    stockCount: number;
    daysAnalyzed: number;
    strongBuy: number;
    buy: number;
    moderateBuy: number;
    hold: number;
    weakSell: number;
    sell: number;
    strongSell: number;
}

interface DataFreshness {
    latestDaily: string | null;
    latestHourly: string | null;
    latestDailyAgo: string | null;
    latestHourlyAgo: string | null;
}

interface MarketStatus {
    isOpen: boolean;
    status: string;
}

interface Props {
    results: AnalysisResult[];
    summary: Summary;
    dataFreshness?: DataFreshness;
    marketStatus?: MarketStatus;
}

export default function TechnicalAnalysis({
    results,
    summary,
    dataFreshness,
    marketStatus,
}: Props) {
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedIndicator, setSelectedIndicator] = useState<string | null>(
        null,
    );

    const indicatorExplanations: Record<
        string,
        { title: string; description: string; details: string[] }
    > = {
        rsi: {
            title: 'RSI (Relative Strength Index)',
            description:
                'RSI measures the speed and magnitude of recent price changes to evaluate overbought or oversold conditions.',
            details: [
                '📊 Range: 0 to 100',
                '🔴 Overbought: RSI > 70 (potential sell signal)',
                '🟢 Oversold: RSI < 30 (potential buy signal)',
                '⚖️ Neutral: RSI between 40-60',
                '⏱️ Period: 14 days (standard)',
                '💡 Best used in ranging markets to identify reversal points',
            ],
        },
        ma: {
            title: 'MA Crossover (Moving Average)',
            description:
                'Compares short-term and long-term moving averages to identify trend changes and momentum shifts.',
            details: [
                '📈 Short MA (20 days): Tracks recent price trends',
                '📉 Long MA (50 days): Tracks longer-term trends',
                '🟢 Golden Cross: Short MA crosses above Long MA (bullish signal)',
                '🔴 Death Cross: Short MA crosses below Long MA (bearish signal)',
                '📊 Price Above Both MAs: Strong uptrend',
                '📉 Price Below Both MAs: Strong downtrend',
                '💡 Popular trend-following indicator used by traders worldwide',
            ],
        },
        bollinger: {
            title: 'Bollinger Bands',
            description:
                'Volatility bands placed above and below a moving average to identify overbought/oversold conditions.',
            details: [
                '📊 Components: Middle Band (20-day SMA), Upper Band (+2σ), Lower Band (-2σ)',
                '🔴 Price at Upper Band: Potentially overbought',
                '🟢 Price at Lower Band: Potentially oversold',
                '🔀 Band Width: Measures market volatility',
                '💥 Bollinger Squeeze: Narrow bands indicate low volatility (potential breakout)',
                '📏 Standard Deviation: 2.0 (captures ~95% of price action)',
                '💡 Excellent for identifying volatility expansion and contraction',
            ],
        },
        volume: {
            title: 'Volume Analysis',
            description:
                'Analyzes trading volume patterns to confirm price movements and identify potential reversals.',
            details: [
                '📊 Measures: Trading volume relative to 30-day average',
                '🚀 Volume Spike (>2x avg) + Price Up: Strong bullish confirmation',
                '⚠️ Volume Spike (>2x avg) + Price Down: Potential panic selling',
                '📉 Low Volume: Weak conviction in price movement',
                '💪 High Volume: Strong market participation and trend confirmation',
                '💡 Volume precedes price - watch for unusual activity',
            ],
        },
        roc: {
            title: 'ROC Momentum (Rate of Change)',
            description:
                'Measures the percentage change in price over a specified period to gauge momentum strength.',
            details: [
                '📊 Period: 10 days',
                '🚀 ROC > +10%: Strong upward momentum',
                '📉 ROC < -10%: Strong downward momentum',
                '⚖️ ROC near 0: Sideways/consolidating market',
                '🔄 Positive ROC: Uptrend gaining strength',
                '📉 Negative ROC: Downtrend gaining strength',
                '💡 Leading indicator - can signal trend changes before price',
            ],
        },
        support: {
            title: 'Support & Resistance',
            description:
                'Identifies key price levels where buying (support) or selling (resistance) pressure is likely to emerge.',
            details: [
                '🟢 Support: Price level where buying interest prevents further decline',
                '🔴 Resistance: Price level where selling interest prevents further advance',
                '📊 Based on: 30-day price range',
                '⬇️ Near Support (<20% of range): Potential buy zone',
                '⬆️ Near Resistance (>80% of range): Potential sell zone',
                '💥 Breakouts: Price breaking through support/resistance can signal strong moves',
                '💡 These levels often become self-fulfilling as traders watch them',
            ],
        },
    };

    // Filter results based on search query
    const filteredResults = useMemo(() => {
        if (!searchQuery.trim()) return results;

        const query = searchQuery.toLowerCase();
        return results.filter(
            (result) =>
                result.symbol.toLowerCase().includes(query) ||
                result.name.toLowerCase().includes(query),
        );
    }, [results, searchQuery]);

    const getRecommendationColor = (recommendation: string) => {
        switch (recommendation) {
            case 'STRONG_BUY':
                return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20';
            case 'BUY':
                return 'bg-green-500/10 text-green-700 dark:text-green-400 border-green-500/20';
            case 'MODERATE_BUY':
                return 'bg-lime-500/10 text-lime-700 dark:text-lime-400 border-lime-500/20';
            case 'HOLD':
                return 'bg-gray-500/10 text-gray-700 dark:text-gray-400 border-gray-500/20';
            case 'WEAK_SELL':
                return 'bg-orange-500/10 text-orange-700 dark:text-orange-400 border-orange-500/20';
            case 'SELL':
                return 'bg-red-500/10 text-red-700 dark:text-red-400 border-red-500/20';
            case 'STRONG_SELL':
                return 'bg-rose-500/10 text-rose-700 dark:text-rose-400 border-rose-500/20';
            default:
                return 'bg-gray-500/10 text-gray-700 dark:text-gray-400 border-gray-500/20';
        }
    };

    const getScoreColor = (score: number) => {
        if (score >= 7) return 'text-emerald-600 dark:text-emerald-400';
        if (score >= 4) return 'text-green-600 dark:text-green-400';
        if (score >= 2) return 'text-lime-600 dark:text-lime-400';
        if (score <= -7) return 'text-rose-600 dark:text-rose-400';
        if (score <= -4) return 'text-red-600 dark:text-red-400';
        if (score <= -2) return 'text-orange-600 dark:text-orange-400';
        return 'text-gray-600 dark:text-gray-400';
    };

    const formatChange = (change: number | null) => {
        if (change === null) return 'n/a';
        const sign = change >= 0 ? '+' : '';
        const color =
            change >= 0
                ? 'text-green-600 dark:text-green-400'
                : 'text-red-600 dark:text-red-400';
        return (
            <span className={color}>
                {sign}
                {change.toFixed(2)}%
            </span>
        );
    };

    const strongBuys = filteredResults.filter((r) => r.score >= 4);
    const moderates = filteredResults.filter(
        (r) => r.score >= 0 && r.score < 4,
    );
    const sells = filteredResults.filter((r) => r.score < 0);

    return (
        <AppLayout>
            <Head title="Technical Analysis" />

            <div className="mx-auto max-w-[1600px] space-y-8 px-4 py-8 sm:px-6 lg:px-8">
                {/* Header */}
                <div>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <h1 className="text-3xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                                📊 Technical Analysis Scanner
                            </h1>
                            {marketStatus && (
                                <div
                                    className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold ${
                                        marketStatus.isOpen
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                            : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400'
                                    }`}
                                >
                                    <span
                                        className={`size-2 rounded-full ${
                                            marketStatus.isOpen
                                                ? 'bg-emerald-600 dark:bg-emerald-400'
                                                : 'bg-gray-600 dark:bg-gray-400'
                                        }`}
                                    />
                                    {marketStatus.status}
                                </div>
                            )}
                        </div>
                        {dataFreshness && (
                            <div className="text-right">
                                <div className="text-xs font-medium text-gray-500 dark:text-gray-400">
                                    Data as of
                                </div>
                                {/* Show hourly freshness during market hours, daily otherwise */}
                                {marketStatus?.isOpen &&
                                dataFreshness.latestHourlyAgo ? (
                                    <>
                                        <div className="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                            {dataFreshness.latestHourlyAgo}
                                        </div>
                                        <div className="text-xs text-gray-500 dark:text-gray-500">
                                            Hourly:{' '}
                                            {dataFreshness.latestHourly
                                                ? new Date(
                                                      dataFreshness.latestHourly,
                                                  ).toLocaleString('en-US', {
                                                      month: 'short',
                                                      day: 'numeric',
                                                      hour: 'numeric',
                                                      minute: '2-digit',
                                                      timeZone:
                                                          'America/New_York',
                                                  }) + ' EST'
                                                : 'n/a'}
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        <div className="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                            {dataFreshness.latestDailyAgo ||
                                                'No data'}
                                        </div>
                                        <div className="text-xs text-gray-500 dark:text-gray-500">
                                            Daily:{' '}
                                            {dataFreshness.latestDaily
                                                ? new Date(
                                                      dataFreshness.latestDaily +
                                                          'T00:00:00',
                                                  ).toLocaleDateString(
                                                      'en-US',
                                                      {
                                                          month: 'short',
                                                          day: 'numeric',
                                                          year: 'numeric',
                                                      },
                                                  )
                                                : 'n/a'}
                                        </div>
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                    <p className="mt-2 max-w-4xl text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        <span className="font-medium text-gray-700 dark:text-gray-300">
                            Technical analysis is calculated using daily closing
                            prices
                        </span>{' '}
                        from the past 90 days, updated after each trading day
                        closes at 4 PM EST (data syncs at 5 PM EST to capture
                        final prices). During market hours, hourly price data
                        provides additional context for short-term momentum.
                        This means analysis reflects end-of-day patterns and
                        trends, not real-time intraday movements—perfect for
                        swing trading and position analysis, but not designed
                        for day trading decisions.
                    </p>
                    <p className="mt-2 max-w-4xl text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        Data-driven insights across {summary.totalAssets} assets
                        using 6 technical indicators.
                        <span className="font-medium text-gray-700 dark:text-gray-300">
                            {' '}
                            Remember: signals reflect market behavior, not
                            guarantees.
                        </span>{' '}
                        When everyone's greedy (strong buy signals everywhere),
                        that's often when corrections happen. When everyone's
                        fearful (strong sell signals), that's when opportunities
                        emerge.{' '}
                        <span className="font-medium text-gray-700 dark:text-gray-300">
                            Before acting on any signal
                        </span>
                        , check recent news, earnings reports, analyst
                        sentiment, social media buzz, and broader market
                        conditions—a "strong buy" signal means nothing if the
                        company just announced terrible earnings or faces
                        regulatory issues.{' '}
                        <span className="italic">
                            Example: A stock showing "STRONG_BUY" with RSI at 25
                            (oversold) might seem attractive, but if the CEO
                            just resigned amid fraud allegations or the FDA
                            rejected their key drug, that technical signal could
                            be a falling knife, not a buy opportunity.
                        </span>{' '}
                        Use these signals alongside fundamentals, news analysis,
                        and your own research—technical analysis is just one
                        piece of the investment puzzle.
                    </p>
                </div>

                {/* Search Filter */}
                <div className="relative">
                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" />
                    <Input
                        type="text"
                        placeholder="Search by symbol or name (e.g., AAPL, Apple, Bitcoin)..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="pl-10"
                    />
                    {searchQuery && (
                        <div className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            Showing {filteredResults.length} of {results.length}{' '}
                            assets
                        </div>
                    )}
                </div>

                {/* Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div className="text-sm font-medium text-gray-600 dark:text-gray-400">
                            Total Assets
                        </div>
                        <div className="mt-2 text-3xl font-bold text-gray-900 dark:text-gray-100">
                            {summary.totalAssets}
                        </div>
                        <div className="mt-1 text-xs text-gray-500 dark:text-gray-500">
                            {summary.stockCount} stocks • {summary.cryptoCount}{' '}
                            crypto
                        </div>
                    </div>

                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-6 dark:border-emerald-800 dark:bg-emerald-950/30">
                        <div className="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                            Strong Buy / Buy
                        </div>
                        <div className="mt-2 text-3xl font-bold text-emerald-900 dark:text-emerald-100">
                            {summary.strongBuy + summary.buy}
                        </div>
                        <div className="mt-1 text-xs text-emerald-600 dark:text-emerald-500">
                            {summary.strongBuy} strong • {summary.buy} regular
                        </div>
                    </div>

                    <div className="rounded-lg border border-lime-200 bg-lime-50 p-6 dark:border-lime-800 dark:bg-lime-950/30">
                        <div className="text-sm font-medium text-lime-700 dark:text-lime-400">
                            Moderate Buy / Hold
                        </div>
                        <div className="mt-2 text-3xl font-bold text-lime-900 dark:text-lime-100">
                            {summary.moderateBuy + summary.hold}
                        </div>
                        <div className="mt-1 text-xs text-lime-600 dark:text-lime-500">
                            {summary.moderateBuy} moderate • {summary.hold} hold
                        </div>
                    </div>

                    <div className="rounded-lg border border-red-200 bg-red-50 p-6 dark:border-red-800 dark:bg-red-950/30">
                        <div className="text-sm font-medium text-red-700 dark:text-red-400">
                            Sell Signals
                        </div>
                        <div className="mt-2 text-3xl font-bold text-red-900 dark:text-red-100">
                            {summary.weakSell +
                                summary.sell +
                                summary.strongSell}
                        </div>
                        <div className="mt-1 text-xs text-red-600 dark:text-red-500">
                            {summary.sell + summary.strongSell} strong •{' '}
                            {summary.weakSell} weak
                        </div>
                    </div>
                </div>

                {/* Legend */}
                <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                    <h3 className="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">
                        Indicators Used (click to learn more):
                    </h3>
                    <div className="flex flex-wrap gap-2 text-xs">
                        <button
                            onClick={() => setSelectedIndicator('rsi')}
                            className="rounded-md bg-white px-3 py-2 font-medium transition-all hover:bg-blue-50 hover:text-blue-700 hover:shadow-md dark:bg-gray-800 dark:hover:bg-blue-900/30 dark:hover:text-blue-400"
                        >
                            RSI (14)
                        </button>
                        <button
                            onClick={() => setSelectedIndicator('ma')}
                            className="rounded-md bg-white px-3 py-2 font-medium transition-all hover:bg-blue-50 hover:text-blue-700 hover:shadow-md dark:bg-gray-800 dark:hover:bg-blue-900/30 dark:hover:text-blue-400"
                        >
                            MA Crossover (20/50)
                        </button>
                        <button
                            onClick={() => setSelectedIndicator('bollinger')}
                            className="rounded-md bg-white px-3 py-2 font-medium transition-all hover:bg-blue-50 hover:text-blue-700 hover:shadow-md dark:bg-gray-800 dark:hover:bg-blue-900/30 dark:hover:text-blue-400"
                        >
                            Bollinger Bands (20,2)
                        </button>
                        <button
                            onClick={() => setSelectedIndicator('volume')}
                            className="rounded-md bg-white px-3 py-2 font-medium transition-all hover:bg-blue-50 hover:text-blue-700 hover:shadow-md dark:bg-gray-800 dark:hover:bg-blue-900/30 dark:hover:text-blue-400"
                        >
                            Volume Analysis
                        </button>
                        <button
                            onClick={() => setSelectedIndicator('roc')}
                            className="rounded-md bg-white px-3 py-2 font-medium transition-all hover:bg-blue-50 hover:text-blue-700 hover:shadow-md dark:bg-gray-800 dark:hover:bg-blue-900/30 dark:hover:text-blue-400"
                        >
                            ROC Momentum (10)
                        </button>
                        <button
                            onClick={() => setSelectedIndicator('support')}
                            className="rounded-md bg-white px-3 py-2 font-medium transition-all hover:bg-blue-50 hover:text-blue-700 hover:shadow-md dark:bg-gray-800 dark:hover:bg-blue-900/30 dark:hover:text-blue-400"
                        >
                            Support/Resistance
                        </button>
                    </div>
                </div>

                {/* Strong Buy / Buy Signals */}
                {strongBuys.length > 0 && (
                    <div>
                        <h2 className="mb-4 flex items-center gap-2 text-2xl font-bold text-gray-900 dark:text-gray-100">
                            <span className="text-2xl">🟢</span>
                            Strong Buy / Buy Signals
                            <span className="text-sm font-normal text-gray-500">
                                ({strongBuys.length})
                            </span>
                        </h2>
                        <div className="space-y-4">
                            {strongBuys.map((result) => (
                                <div
                                    key={`${result.type}-${result.symbol}`}
                                    className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-4">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-3">
                                                <a
                                                    href={showAsset.url(
                                                        result.id,
                                                    )}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-2xl font-bold text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                >
                                                    {result.symbol}
                                                </a>
                                                <span className="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 uppercase dark:bg-gray-700 dark:text-gray-400">
                                                    {result.type}
                                                </span>
                                                <span
                                                    className={`rounded-md border px-3 py-1 text-sm font-semibold ${getRecommendationColor(result.recommendation)}`}
                                                >
                                                    {result.recommendation.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </span>
                                            </div>
                                            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                                {result.name}
                                            </p>
                                            <div className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        Price:
                                                    </span>{' '}
                                                    <span className="font-semibold text-gray-900 dark:text-gray-100">
                                                        $
                                                        {result.price.toLocaleString()}
                                                    </span>
                                                </div>
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        Score:
                                                    </span>{' '}
                                                    <span
                                                        className={`font-bold ${getScoreColor(result.score)}`}
                                                    >
                                                        {result.score > 0
                                                            ? '+'
                                                            : ''}
                                                        {result.score}
                                                    </span>
                                                </div>
                                                {result.rsi !== null && (
                                                    <div>
                                                        <span className="text-gray-500 dark:text-gray-400">
                                                            RSI:
                                                        </span>{' '}
                                                        <span className="font-semibold text-gray-900 dark:text-gray-100">
                                                            {result.rsi.toFixed(
                                                                1,
                                                            )}
                                                        </span>
                                                    </div>
                                                )}
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        3h:
                                                    </span>{' '}
                                                    {formatChange(
                                                        result.threeHourChange,
                                                    )}
                                                </div>
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        5h:
                                                    </span>{' '}
                                                    {formatChange(
                                                        result.fiveHourChange,
                                                    )}
                                                </div>
                                            </div>
                                            <div className="mt-4 space-y-1">
                                                {result.signals.map(
                                                    (signal, idx) => (
                                                        <div
                                                            key={idx}
                                                            className="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-400"
                                                        >
                                                            <span className="text-emerald-500">
                                                                •
                                                            </span>
                                                            <span>
                                                                {signal}
                                                            </span>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Moderate Buy / Hold Signals */}
                {moderates.length > 0 && (
                    <div>
                        <h2 className="mb-4 flex items-center gap-2 text-2xl font-bold text-gray-900 dark:text-gray-100">
                            <span className="text-2xl">🟡</span>
                            Moderate Buy / Hold Signals
                            <span className="text-sm font-normal text-gray-500">
                                ({moderates.length})
                            </span>
                        </h2>
                        <div className="grid gap-4 lg:grid-cols-2">
                            {moderates.map((result) => (
                                <div
                                    key={`${result.type}-${result.symbol}`}
                                    className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                                >
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2">
                                                <a
                                                    href={showAsset.url(
                                                        result.id,
                                                    )}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-lg font-bold text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                >
                                                    {result.symbol}
                                                </a>
                                                <span className="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 uppercase dark:bg-gray-700 dark:text-gray-400">
                                                    {result.type}
                                                </span>
                                                <span
                                                    className={`rounded-md border px-2 py-0.5 text-xs font-semibold ${getRecommendationColor(result.recommendation)}`}
                                                >
                                                    {result.recommendation.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </span>
                                            </div>
                                            <p className="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                                {result.name}
                                            </p>
                                            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        Price:
                                                    </span>{' '}
                                                    <span className="font-semibold text-gray-900 dark:text-gray-100">
                                                        $
                                                        {result.price.toLocaleString()}
                                                    </span>
                                                </div>
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        Score:
                                                    </span>{' '}
                                                    <span
                                                        className={`font-bold ${getScoreColor(result.score)}`}
                                                    >
                                                        {result.score > 0
                                                            ? '+'
                                                            : ''}
                                                        {result.score}
                                                    </span>
                                                </div>
                                                {result.rsi !== null && (
                                                    <div>
                                                        <span className="text-gray-500 dark:text-gray-400">
                                                            RSI:
                                                        </span>{' '}
                                                        <span className="font-semibold text-gray-900 dark:text-gray-100">
                                                            {result.rsi.toFixed(
                                                                1,
                                                            )}
                                                        </span>
                                                    </div>
                                                )}
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        3h:
                                                    </span>{' '}
                                                    {formatChange(
                                                        result.threeHourChange,
                                                    )}
                                                </div>
                                            </div>
                                            <div className="mt-2 space-y-0.5">
                                                {result.signals
                                                    .slice(0, 3)
                                                    .map((signal, idx) => (
                                                        <div
                                                            key={idx}
                                                            className="flex items-start gap-1.5 text-xs text-gray-600 dark:text-gray-400"
                                                        >
                                                            <span className="text-lime-500">
                                                                •
                                                            </span>
                                                            <span>
                                                                {signal}
                                                            </span>
                                                        </div>
                                                    ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Sell Signals */}
                {sells.length > 0 && (
                    <div>
                        <h2 className="mb-4 flex items-center gap-2 text-2xl font-bold text-gray-900 dark:text-gray-100">
                            <span className="text-2xl">🔴</span>
                            Sell / Weak Sell Signals
                            <span className="text-sm font-normal text-gray-500">
                                ({sells.length})
                            </span>
                        </h2>
                        <div className="grid gap-4 lg:grid-cols-3">
                            {sells.map((result) => (
                                <div
                                    key={`${result.type}-${result.symbol}`}
                                    className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                                >
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2">
                                                <a
                                                    href={showAsset.url(
                                                        result.id,
                                                    )}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-lg font-bold text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                >
                                                    {result.symbol}
                                                </a>
                                                <span className="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 uppercase dark:bg-gray-700 dark:text-gray-400">
                                                    {result.type}
                                                </span>
                                            </div>
                                            <p className="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                                {result.name}
                                            </p>
                                            <span
                                                className={`mt-1 inline-block rounded-md border px-2 py-0.5 text-xs font-semibold ${getRecommendationColor(result.recommendation)}`}
                                            >
                                                {result.recommendation.replace(
                                                    '_',
                                                    ' ',
                                                )}
                                            </span>
                                            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        Price:
                                                    </span>{' '}
                                                    <span className="font-semibold text-gray-900 dark:text-gray-100">
                                                        $
                                                        {result.price.toLocaleString()}
                                                    </span>
                                                </div>
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        Score:
                                                    </span>{' '}
                                                    <span
                                                        className={`font-bold ${getScoreColor(result.score)}`}
                                                    >
                                                        {result.score}
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="mt-2 space-y-0.5">
                                                {result.signals
                                                    .slice(0, 2)
                                                    .map((signal, idx) => (
                                                        <div
                                                            key={idx}
                                                            className="flex items-start gap-1.5 text-xs text-gray-600 dark:text-gray-400"
                                                        >
                                                            <span className="text-red-500">
                                                                •
                                                            </span>
                                                            <span>
                                                                {signal}
                                                            </span>
                                                        </div>
                                                    ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Indicator Explanation Modal */}
            <Dialog
                open={selectedIndicator !== null}
                onOpenChange={(open) => !open && setSelectedIndicator(null)}
            >
                <DialogContent className="max-w-2xl">
                    {selectedIndicator &&
                        indicatorExplanations[selectedIndicator] && (
                            <>
                                <DialogHeader>
                                    <DialogTitle className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                        {
                                            indicatorExplanations[
                                                selectedIndicator
                                            ].title
                                        }
                                    </DialogTitle>
                                    <DialogDescription className="text-base text-gray-600 dark:text-gray-400">
                                        {
                                            indicatorExplanations[
                                                selectedIndicator
                                            ].description
                                        }
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="mt-4 space-y-3">
                                    {indicatorExplanations[
                                        selectedIndicator
                                    ].details.map((detail, idx) => (
                                        <div
                                            key={idx}
                                            className="flex items-start gap-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50"
                                        >
                                            <span className="text-lg leading-none">
                                                {detail.split(' ')[0]}
                                            </span>
                                            <p className="flex-1 text-sm text-gray-700 dark:text-gray-300">
                                                {detail.substring(
                                                    detail.indexOf(' ') + 1,
                                                )}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
