import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

type DataFreshness = {
    five_minute: {
        latest: string | null;
        ago: string | null;
        minutes_ago: number | null;
    };
    hourly: {
        latest: string | null;
        ago: string | null;
        hours_ago: number | null;
    };
    daily: {
        latest: string | null;
        ago: string | null;
        days_ago: number | null;
    };
};

const marketDataCards = [
    {
        title: 'Technical Analysis',
        description: 'Advanced multi-indicator analysis with buy/sell signals',
        href: '/market-data/technical-analysis',
        icon: '📊',
        color: 'from-emerald-500 to-green-600',
    },
    {
        title: 'View Assets',
        description: 'Browse all S&P 500 stocks',
        href: '/market-data/assets',
        icon: '💼',
        color: 'from-blue-500 to-indigo-600',
    },
    // {
    //     title: 'Daily Prices',
    //     description: 'Historical daily price data and trends',
    //     href: '/market-data/daily-prices',
    //     icon: '📈',
    //     color: 'from-purple-500 to-pink-600',
    // },
    // {
    //     title: 'Hourly Prices',
    //     description: 'Intraday hourly price movements',
    //     href: '/market-data/hourly-prices',
    //     icon: '⏱️',
    //     color: 'from-orange-500 to-red-600',
    // },
];

const investmentCards = [
    {
        title: 'Deposits',
        description: 'Track your cash deposits and account balance',
        href: '/deposits',
        icon: '💰',
        color: 'from-green-500 to-emerald-600',
    },
    {
        title: 'Stock Transactions',
        description: 'Buy and sell stocks, view transaction history',
        href: '/stock-transactions',
        icon: '📝',
        color: 'from-cyan-500 to-blue-600',
    },
    {
        title: 'Quick Import',
        description: 'Bulk import transactions from CSV or text',
        href: '/quick-import',
        icon: '⚡',
        color: 'from-violet-500 to-purple-600',
    },
];

export default function Dashboard({
    auth,
    dataFreshness,
    userIsAdmin,
    assetStats,
}: {
    auth: { user: any; isGuest: boolean };
    dataFreshness: DataFreshness;
    userIsAdmin: boolean;
    assetStats: {
        total: number;
        stocks: number;
        crypto: number;
        sp500: number;
    };
}) {
    // Helper function to determine freshness status color
    const getFreshnessStatus = (
        type: '5min' | 'hourly' | 'daily',
        value: number | null,
    ) => {
        if (value === null)
            return {
                color: 'text-gray-500',
                bgColor: 'bg-gray-100 dark:bg-gray-800',
                status: 'No data',
            };

        if (type === '5min') {
            if (value <= 10)
                return {
                    color: 'text-emerald-600 dark:text-emerald-400',
                    bgColor: 'bg-emerald-50 dark:bg-emerald-950/30',
                    status: 'Fresh',
                };
            if (value <= 30)
                return {
                    color: 'text-yellow-600 dark:text-yellow-400',
                    bgColor: 'bg-yellow-50 dark:bg-yellow-950/30',
                    status: 'Recent',
                };
            return {
                color: 'text-red-600 dark:text-red-400',
                bgColor: 'bg-red-50 dark:bg-red-950/30',
                status: 'Stale',
            };
        }

        if (type === 'hourly') {
            if (value <= 2)
                return {
                    color: 'text-emerald-600 dark:text-emerald-400',
                    bgColor: 'bg-emerald-50 dark:bg-emerald-950/30',
                    status: 'Fresh',
                };
            if (value <= 24)
                return {
                    color: 'text-yellow-600 dark:text-yellow-400',
                    bgColor: 'bg-yellow-50 dark:bg-yellow-950/30',
                    status: 'Recent',
                };
            return {
                color: 'text-red-600 dark:text-red-400',
                bgColor: 'bg-red-50 dark:bg-red-950/30',
                status: 'Stale',
            };
        }

        // daily
        if (value <= 1)
            return {
                color: 'text-emerald-600 dark:text-emerald-400',
                bgColor: 'bg-emerald-50 dark:bg-emerald-950/30',
                status: 'Fresh',
            };
        if (value <= 7)
            return {
                color: 'text-yellow-600 dark:text-yellow-400',
                bgColor: 'bg-yellow-50 dark:bg-yellow-950/30',
                status: 'Recent',
            };
        return {
            color: 'text-red-600 dark:text-red-400',
            bgColor: 'bg-red-50 dark:bg-red-950/30',
            status: 'Stale',
        };
    };

    // Helper function to check if market is currently open (US stock market hours)
    const getMarketStatus = () => {
        const now = new Date();
        const dayOfWeek = now.getDay(); // 0 = Sunday, 6 = Saturday

        // Market is closed on weekends
        if (dayOfWeek === 0 || dayOfWeek === 6) {
            return {
                isOpen: false,
                status: 'Closed (Weekend)',
                statusColor: 'text-gray-600 dark:text-gray-400',
                message: 'Markets reopen Monday at 9:30 AM ET',
            };
        }

        // Get current time in EST/EDT
        const estFormatter = new Intl.DateTimeFormat('en-US', {
            timeZone: 'America/New_York',
            hour12: false,
            hour: '2-digit',
            minute: '2-digit',
        });

        const estTime = estFormatter.format(now);
        const [estHour, estMinute] = estTime.split(':').map(Number);
        const currentTimeInMinutes = estHour * 60 + estMinute;

        // Market hours: 9:30 AM - 4:00 PM ET
        const marketOpen = 9 * 60 + 30; // 9:30 AM
        const marketClose = 16 * 60; // 4:00 PM

        if (
            currentTimeInMinutes >= marketOpen &&
            currentTimeInMinutes < marketClose
        ) {
            return {
                isOpen: true,
                status: 'Open',
                statusColor: 'text-emerald-600 dark:text-emerald-400',
                message: 'Market closes at 4:00 PM ET',
            };
        } else if (currentTimeInMinutes < marketOpen) {
            return {
                isOpen: false,
                status: 'Pre-Market',
                statusColor: 'text-blue-600 dark:text-blue-400',
                message: 'Market opens at 9:30 AM ET',
            };
        } else {
            return {
                isOpen: false,
                status: 'After Hours',
                statusColor: 'text-orange-600 dark:text-orange-400',
                message: 'Market opens tomorrow at 9:30 AM ET',
            };
        }
    };

    const fiveMinStatus = getFreshnessStatus(
        '5min',
        dataFreshness.five_minute.minutes_ago,
    );
    const hourlyStatus = getFreshnessStatus(
        'hourly',
        dataFreshness.hourly.hours_ago,
    );
    const dailyStatus = getFreshnessStatus(
        'daily',
        dataFreshness.daily.days_ago,
    );
    const marketStatus = getMarketStatus();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
                {/* Welcome Section */}
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                        Welcome to Your Investment Dashboard
                    </h1>
                    <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Manage your investments, track market data, and analyze
                        trading opportunities
                    </p>
                    <div className="mt-3 flex items-start gap-2 rounded-md border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-950/30">
                        <span className="text-blue-600 dark:text-blue-400">
                            ℹ️
                        </span>
                        <p className="text-sm text-blue-700 dark:text-blue-300">
                            <strong>Please note:</strong> Our analytics are
                            crunching through millions of data points to provide
                            you with accurate insights. Some pages may take a
                            moment to load while we process the latest market
                            information.
                        </p>
                    </div>
                </div>

                {/* Market Data Section */}
                <div>
                    <h2 className="mb-4 text-xl font-semibold text-gray-900 dark:text-gray-100">
                        📊 Market Data & Analysis
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {marketDataCards.map((card) => (
                            <Link
                                key={card.href}
                                href={card.href}
                                className="group relative overflow-hidden rounded-lg border border-gray-200 bg-white p-6 transition-all hover:shadow-lg dark:border-gray-700 dark:bg-gray-800"
                            >
                                <div
                                    className={`absolute inset-0 bg-gradient-to-br ${card.color} opacity-0 transition-opacity group-hover:opacity-5`}
                                />
                                <div className="relative">
                                    <div className="mb-3 text-4xl">
                                        {card.icon}
                                    </div>
                                    <h3 className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        {card.title}
                                    </h3>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">
                                        {card.description}
                                    </p>
                                </div>
                            </Link>
                        ))}
                    </div>
                </div>

                {/* Investment Management Section - HIDDEN FOR NOW, TO BE FIXED LATER */}
                {false && (
                <div>
                    <h2 className="mb-4 text-xl font-semibold text-gray-900 dark:text-gray-100">
                        💼 Investment Management
                    </h2>
                    {auth.isGuest && (
                        <div className="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-800 dark:bg-yellow-900/20">
                            <p className="text-sm text-yellow-800 dark:text-yellow-200">
                                💡 Guest users have view-only access. Investment
                                management features are disabled.
                            </p>
                        </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {investmentCards.map((card) =>
                            auth.isGuest ? (
                                <div
                                    key={card.href}
                                    className="relative cursor-not-allowed overflow-hidden rounded-lg border border-gray-200 bg-white p-6 opacity-50 dark:border-gray-700 dark:bg-gray-800"
                                >
                                    <div
                                        className={`absolute inset-0 bg-gradient-to-br ${card.color} opacity-5`}
                                    />
                                    <div className="relative">
                                        <div className="mb-3 text-4xl grayscale">
                                            {card.icon}
                                        </div>
                                        <h3 className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                            {card.title}
                                        </h3>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">
                                            {card.description}
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <Link
                                    key={card.href}
                                    href={card.href}
                                    className="group relative overflow-hidden rounded-lg border border-gray-200 bg-white p-6 transition-all hover:shadow-lg dark:border-gray-700 dark:bg-gray-800"
                                >
                                    <div
                                        className={`absolute inset-0 bg-gradient-to-br ${card.color} opacity-0 transition-opacity group-hover:opacity-5`}
                                    />
                                    <div className="relative">
                                        <div className="mb-3 text-4xl">
                                            {card.icon}
                                        </div>
                                        <h3 className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                            {card.title}
                                        </h3>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">
                                            {card.description}
                                        </p>
                                    </div>
                                </Link>
                            ),
                        )}
                    </div>
                </div>
                )}

                {/* Quick Stats */}
                <div className="rounded-lg border border-gray-200 bg-gradient-to-br from-gray-50 to-gray-100 p-6 dark:border-gray-700 dark:from-gray-800 dark:to-gray-900">
                    <h3 className="mb-3 text-lg font-semibold text-gray-900 dark:text-gray-100">
                        🎯 Quick Stats
                    </h3>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <div className="text-sm text-gray-600 dark:text-gray-400">
                                Total Assets Tracked
                            </div>
                            <div className="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">
                                {assetStats.total.toLocaleString()}
                            </div>
                            <div className="mt-0.5 text-xs text-gray-500">
                                {assetStats.stocks} stocks
                            </div>
                        </div>
                        <div>
                            <div className="text-sm text-gray-600 dark:text-gray-400">
                                S&P 500 Coverage
                            </div>
                            <div className="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                                {Math.round((assetStats.sp500 / 503) * 100)}%
                            </div>
                        </div>
                        <div className="sm:col-span-2">
                            <div className="mb-2 flex items-center justify-between">
                                <div className="text-sm text-gray-600 dark:text-gray-400">
                                    Market Data Freshness
                                </div>
                                <div className="flex items-center gap-2">
                                    <span
                                        className={`text-xs font-semibold ${marketStatus.statusColor}`}
                                    >
                                        {marketStatus.isOpen ? '🟢' : '🔴'}{' '}
                                        {marketStatus.status}
                                    </span>
                                </div>
                            </div>
                            <div className="mb-2 rounded-md border border-gray-300 bg-gray-50 px-3 py-1.5 dark:border-gray-600 dark:bg-gray-800">
                                <p className="text-xs text-gray-600 dark:text-gray-400">
                                    📅 {marketStatus.message}
                                </p>
                                <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-500">
                                    Regular hours: Mon-Fri 9:30 AM - 4:00 PM ET
                                </p>
                            </div>
                            <div className="space-y-2">
                                <div
                                    className={`flex items-center justify-between rounded-md px-3 py-2 ${fiveMinStatus.bgColor}`}
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs font-medium text-gray-600 dark:text-gray-400">
                                            5-Minute
                                        </span>
                                        <span
                                            className={`text-xs font-semibold ${fiveMinStatus.color}`}
                                        >
                                            {fiveMinStatus.status}
                                        </span>
                                    </div>
                                    <span className="text-xs text-gray-600 dark:text-gray-400">
                                        {dataFreshness.five_minute.ago || 'N/A'}
                                    </span>
                                </div>
                                <div
                                    className={`flex items-center justify-between rounded-md px-3 py-2 ${hourlyStatus.bgColor}`}
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs font-medium text-gray-600 dark:text-gray-400">
                                            Hourly
                                        </span>
                                        <span
                                            className={`text-xs font-semibold ${hourlyStatus.color}`}
                                        >
                                            {hourlyStatus.status}
                                        </span>
                                    </div>
                                    <span className="text-xs text-gray-600 dark:text-gray-400">
                                        {dataFreshness.hourly.ago || 'N/A'}
                                    </span>
                                </div>
                                <div
                                    className={`flex items-center justify-between rounded-md px-3 py-2 ${dailyStatus.bgColor}`}
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs font-medium text-gray-600 dark:text-gray-400">
                                            Daily
                                        </span>
                                        <span
                                            className={`text-xs font-semibold ${dailyStatus.color}`}
                                        >
                                            {dailyStatus.status}
                                        </span>
                                    </div>
                                    <span className="text-xs text-gray-600 dark:text-gray-400">
                                        {dataFreshness.daily.ago || 'N/A'}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Admin Section */}
                {userIsAdmin && (
                    <div className="rounded-lg border border-purple-200 bg-gradient-to-br from-purple-50 to-pink-50 p-6 dark:border-purple-900 dark:from-purple-950/30 dark:to-pink-950/30">
                        <h3 className="mb-3 text-lg font-semibold text-gray-900 dark:text-gray-100">
                            ⚙️ Administration
                        </h3>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Link
                                href="/market-data/assets/add"
                                className="group relative overflow-hidden rounded-lg border border-purple-200 bg-white p-6 transition-all hover:shadow-lg dark:border-purple-800 dark:bg-gray-800"
                            >
                                <div className="absolute inset-0 bg-gradient-to-br from-purple-500 to-pink-600 opacity-0 transition-opacity group-hover:opacity-5" />
                                <div className="relative">
                                    <div className="mb-3 text-4xl">➕</div>
                                    <h4 className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        Add New Symbol
                                    </h4>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">
                                        Add stocks or cryptocurrencies to track.
                                        Market data will be fetched
                                        automatically.
                                    </p>
                                </div>
                            </Link>
                            <div className="relative overflow-hidden rounded-lg border border-purple-200 bg-white p-6 dark:border-purple-800 dark:bg-gray-800">
                                <div className="absolute inset-0 bg-gradient-to-br from-purple-500 to-pink-600 opacity-5" />
                                <div className="relative">
                                    <div className="mb-3 text-4xl">🔐</div>
                                    <h4 className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        Admin Panel
                                    </h4>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">
                                        More admin features coming soon...
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Support & Resources */}
                <div className="rounded-lg border border-gray-200 bg-gradient-to-br from-blue-50 to-indigo-50 p-6 dark:border-gray-700 dark:from-gray-800 dark:to-gray-900">
                    <h3 className="mb-3 text-lg font-semibold text-gray-900 dark:text-gray-100">
                        💬 Support & Resources
                    </h3>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Link
                            href="/support/help-desk"
                            className="group rounded-lg border border-blue-200 bg-white p-4 text-center transition-all hover:border-blue-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-blue-500"
                        >
                            <div className="text-2xl">🎫</div>
                            <div className="mt-2 font-semibold text-gray-900 dark:text-gray-100">
                                Help Desk
                            </div>
                            <div className="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                Get support
                            </div>
                        </Link>
                        <Link
                            href="/support/feature-request"
                            className="group rounded-lg border border-purple-200 bg-white p-4 text-center transition-all hover:border-purple-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-purple-500"
                        >
                            <div className="text-2xl">💡</div>
                            <div className="mt-2 font-semibold text-gray-900 dark:text-gray-100">
                                Feature Request
                            </div>
                            <div className="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                Share your ideas
                            </div>
                        </Link>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
