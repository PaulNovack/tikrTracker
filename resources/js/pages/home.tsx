import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    Bell,
    LineChart,
    Shield,
    TrendingUp,
    Zap,
} from 'lucide-react';

export default function Home() {
    return (
        <>
            <Head title="Welcome to tikrTracker" />

            <div className="min-h-screen bg-gradient-to-b from-white to-gray-50 dark:from-gray-950 dark:to-gray-900">
                {/* Header */}
                <header className="border-b border-gray-200 dark:border-gray-800">
                    <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                        <div className="flex items-center gap-2">
                            <TrendingUp className="size-8 text-emerald-600" />
                            <span className="text-2xl font-bold text-gray-900 dark:text-white">
                                tikrTracker
                            </span>
                        </div>
                        <nav className="flex items-center gap-4">
                            <Link href="/contact">
                                <Button variant="ghost">Contact Us</Button>
                            </Link>
                            <Link href="/login">
                                <Button>Sign In</Button>
                            </Link>
                        </nav>
                    </div>
                </header>

                {/* Hero Section */}
                <section className="px-4 py-20 sm:px-6 lg:px-8">
                    <div className="mx-auto max-w-7xl">
                        <div className="text-center">
                            <div className="mb-6 inline-flex items-center gap-2 rounded-full bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                <Bell className="size-4" />
                                Currently in Beta - Beta Users Only
                            </div>
                            <h1 className="mb-6 text-5xl font-bold tracking-tight text-gray-900 sm:text-6xl dark:text-white">
                                Smart Trading Made Simple
                                <br />
                                <span className="bg-gradient-to-r from-emerald-600 to-blue-600 bg-clip-text text-transparent">
                                    AI-Powered Stock Market Insights
                                </span>
                            </h1>
                            <p className="mx-auto mb-8 max-w-2xl text-xl text-gray-600 dark:text-gray-400">
                                Get daily stock recommendations powered by artificial intelligence. 
                                Our system tracks over 4,000 stocks with 5-minute precision and 3,600+ stocks 
                                with 1-minute data, showing you the most profitable opportunities every day.
                            </p>
                            <div className="flex flex-wrap justify-center gap-4">
                                <Link href="/guest-login">
                                    <Button
                                        size="lg"
                                        className="gap-2"
                                        disabled
                                    >
                                        Try Guest Mode
                                        <ArrowRight className="size-4" />
                                    </Button>
                                </Link>
                                <Link href="/contact">
                                    <Button size="lg" variant="outline">
                                        Request Beta Access
                                    </Button>
                                </Link>
                            </div>
                            <p className="mt-4 text-sm text-gray-500 dark:text-gray-500">
                                System access is limited to approved beta users
                                only. Request access to join our testing
                                program.
                            </p>
                        </div>
                    </div>
                </section>

                {/* Features Grid */}
                <section className="px-4 py-12 sm:px-6 lg:px-8">
                    <div className="mx-auto max-w-7xl">
                        <div className="mb-12 text-center">
                            <h2 className="mb-4 text-3xl font-bold text-gray-900 dark:text-white">
                                Powerful Trading Tools for Everyone
                            </h2>
                            <p className="text-lg text-gray-600 dark:text-gray-400">
                                AI-powered stock picks with clear buy and sell recommendations
                            </p>
                        </div>

                        <div className="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                            {/* Feature 1 - ML Scoring System */}
                            <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                                <div className="mb-4 inline-flex rounded-lg bg-emerald-100 p-3 dark:bg-emerald-900/30">
                                    <BarChart3 className="size-6 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <h3 className="mb-2 text-xl font-semibold text-gray-900 dark:text-white">
                                    AI Success Predictions
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    Every stock recommendation comes with an AI-calculated success score 
                                    showing how likely it is to be profitable. Focus on the highest-rated 
                                    opportunities and skip the risky ones.
                                </p>
                            </div>

                            {/* Feature 2 - Multiple Trading Pipelines */}
                            <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                                <div className="mb-4 inline-flex rounded-lg bg-blue-100 p-3 dark:bg-blue-900/30">
                                    <Zap className="size-6 text-blue-600 dark:text-blue-400" />
                                </div>
                                <h3 className="mb-2 text-xl font-semibold text-gray-900 dark:text-white">
                                    Multiple Trading Strategies
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    Five different trading strategies work together to find opportunities. 
                                    Each one looks for different patterns in the market, giving you more 
                                    chances to find winning stocks every day.
                                </p>
                            </div>

                            {/* Feature 3 - Real-Time Data */}
                            <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                                <div className="mb-4 inline-flex rounded-lg bg-purple-100 p-3 dark:bg-purple-900/30">
                                    <LineChart className="size-6 text-purple-600 dark:text-purple-400" />
                                </div>
                                <h3 className="mb-2 text-xl font-semibold text-gray-900 dark:text-white">
                                    Always Up-to-Date
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    Track over 4,000 stocks with 5-minute updates and 3,600+ stocks with 
                                    1-minute precision throughout the trading day. Always working with the 
                                    latest market information so you never miss an opportunity.
                                </p>
                            </div>

                            {/* Feature 4 - Comprehensive Backtesting */}
                            <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                                <div className="mb-4 inline-flex rounded-lg bg-orange-100 p-3 dark:bg-orange-900/30">
                                    <TrendingUp className="size-6 text-orange-600 dark:text-orange-400" />
                                </div>
                                <h3 className="mb-2 text-xl font-semibold text-gray-900 dark:text-white">
                                    Proven Track Record
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    See how our strategies performed over the past 6+ months using real 
                                    historical data. Check win rates and profit performance before you 
                                    make any decisions. No guessing - just facts.
                                </p>
                            </div>

                            {/* Feature 5 - Real-Time Trade Alerts */}
                            <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                                <div className="mb-4 inline-flex rounded-lg bg-red-100 p-3 dark:bg-red-900/30">
                                    <BarChart3 className="size-6 text-red-600 dark:text-red-400" />
                                </div>
                                <h3 className="mb-2 text-xl font-semibold text-gray-900 dark:text-white">
                                    Daily Stock Recommendations
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    Get clear buy recommendations with exact prices, when to exit to protect 
                                    your money, and target profit levels. Every recommendation includes a 
                                    success score and risk rating so you can decide what's right for you.
                                </p>
                            </div>

                            {/* Feature 6 - Instant Buy Alerts */}
                            <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                                <div className="mb-4 inline-flex rounded-lg bg-teal-100 p-3 dark:bg-teal-900/30">
                                    <Bell className="size-6 text-teal-600 dark:text-teal-400" />
                                </div>
                                <h3 className="mb-2 text-xl font-semibold text-gray-900 dark:text-white">
                                    Instant Buy Notifications
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    Get real-time pop-up notifications with sound alerts the moment a high-probability 
                                    buy opportunity appears. No need to constantly watch the screen - we alert you 
                                    instantly with exact entry prices, stop levels, and profit targets.
                                </p>
                            </div>

                            {/* Feature 7 - Technical Analysis */}
                            <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                                <div className="mb-4 inline-flex rounded-lg bg-indigo-100 p-3 dark:bg-indigo-900/30">
                                    <Shield className="size-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <h3 className="mb-2 text-xl font-semibold text-gray-900 dark:text-white">
                                    Smart Market Analysis
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    Our AI analyzes dozens of market patterns and signals that professional 
                                    traders use. It spots trends, price breakouts, and momentum shifts - 
                                    all the complex stuff is done for you automatically.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                {/* How It Works */}
                <section className="bg-gray-100 px-4 py-20 sm:px-6 lg:px-8 dark:bg-gray-800/50">
                    <div className="mx-auto max-w-7xl">
                        <div className="mb-12 text-center">
                            <h2 className="mb-4 text-3xl font-bold text-gray-900 dark:text-white">
                                How It Works
                            </h2>
                            <p className="text-lg text-gray-600 dark:text-gray-400">
                                Get started in minutes
                            </p>
                        </div>

                        <div className="grid gap-8 md:grid-cols-3">
                            <div className="text-center">
                                <div className="mb-4 inline-flex size-16 items-center justify-center rounded-full bg-emerald-100 text-2xl font-bold text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                                    1
                                </div>
                                <h3 className="mb-2 text-xl font-semibold text-gray-900 dark:text-white">
                                    AI Learns from History
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    Our AI studies thousands of past trades - both winners and 
                                    losers - to get smarter over time. It learns what patterns 
                                    lead to success and which ones to avoid.
                                </p>
                            </div>

                            <div className="text-center">
                                <div className="mb-4 inline-flex size-16 items-center justify-center rounded-full bg-blue-100 text-2xl font-bold text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                                    2
                                </div>
                                <h3 className="mb-2 text-xl font-semibold text-gray-900 dark:text-white">
                                    Find Daily Opportunities
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    The system scans over 4,000 stocks with 5-minute updates (plus 3,600+ 
                                    with 1-minute precision) throughout the trading day, looking for opportunities. 
                                    It ranks them by success probability and shows you only the best ones.
                                </p>
                            </div>

                            <div className="text-center">
                                <div className="mb-4 inline-flex size-16 items-center justify-center rounded-full bg-purple-100 text-2xl font-bold text-purple-600 dark:bg-purple-900/30 dark:text-purple-400">
                                    3
                                </div>
                                <h3 className="mb-2 text-xl font-semibold text-gray-900 dark:text-white">
                                    Make Informed Decisions
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    Review clear recommendations with buy prices, exit points, 
                                    and profit goals. See how each strategy performs and choose 
                                    the trades that match your comfort level.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                {/* Beta Notice */}
                <section className="px-4 py-20 sm:px-6 lg:px-8">
                    <div className="mx-auto max-w-4xl">
                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-8 dark:border-emerald-800 dark:bg-emerald-900/20">
                            <div className="text-center">
                                <div className="mb-4 inline-flex rounded-full bg-emerald-100 p-3 dark:bg-emerald-900/50">
                                    <Bell className="size-8 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <h2 className="mb-4 text-3xl font-bold text-gray-900 dark:text-white">
                                    Active Development & Improvements
                                </h2>
                                <p className="mb-6 text-lg text-gray-700 dark:text-gray-300">
                                    An AI-powered stock picking system with five proven trading strategies, 
                                    6+ months of proven performance data, and automatic daily market scanning. 
                                    Get clear buy and sell recommendations backed by real data. 
                                    Currently in active development and improvement.
                                </p>
                                <div className="flex flex-wrap justify-center gap-4">
                                    <Link href="/contact">
                                        <Button size="lg" variant="default">
                                            Request Beta Access
                                        </Button>
                                    </Link>
                                    <Link href="/login">
                                        <Button size="lg" variant="outline">
                                            Sign In
                                        </Button>
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* Footer */}
                <footer className="border-t border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
                    <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                        <div className="flex items-center justify-center gap-2">
                            <TrendingUp className="size-6 text-emerald-600" />
                            <span className="text-xl font-bold text-gray-900 dark:text-white">
                                tikrTracker
                            </span>
                        </div>
                        <p className="mt-4 text-center text-sm text-gray-600 dark:text-gray-400">
                            AI-powered stock recommendations with 4,000+ stocks tracked at 5-minute intervals 
                            and 3,600+ stocks with 1-minute precision. Automatic daily market scanning.
                        </p>
                        <p className="mt-4 text-center text-sm text-gray-500 dark:text-gray-500">
                            © {new Date().getFullYear()} tikrTracker. All
                            rights reserved. Development Version.
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}
