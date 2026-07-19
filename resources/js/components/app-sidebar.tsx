import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as editTradingSettings } from '@/actions/App/Http/Controllers/TradingSettingsController';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Activity, Bell, BarChart, Building, Camera, Clock, Database, DollarSign, Eye, FileText, GraduationCap, History, Key, LayoutGrid, List, MessageSquare, Settings, Shield, ShoppingCart, StopCircle, Target, Thermometer, TrendingDown, TrendingUp, Upload, Zap } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { auth } = usePage().props;
    const isGuest = (auth as { isGuest: boolean }).isGuest;
    const isAdmin =
        (auth as { user?: { role?: string } }).user?.role === 'admin';
    const [notificationCounts, setNotificationCounts] = useState({
        read: 0,
        unread: 0,
    });

    // Fetch notification counts
    const fetchNotificationCounts = useCallback(() => {
        if (!isGuest) {
            fetch('/api/notifications/counts')
                .then((res) => res.json())
                .then((data) => setNotificationCounts(data))
                .catch(() => setNotificationCounts({ read: 0, unread: 0 }));
        }
    }, [isGuest]);

    // Fetch on mount and set up polling every minute
    useEffect(() => {
        fetchNotificationCounts();

        const pollInterval = setInterval(fetchNotificationCounts, 60 * 1000); // 60 seconds

        return () => clearInterval(pollInterval);
    }, [fetchNotificationCounts]);

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Trade Alerts',
            href: '/trade-alerts',
            icon: Bell,
            disabled: isGuest,
        },
        {
            title: 'Webull',
            href: '/webull-positions', // Default to positions page
            icon: Building,
            hidden: true,
            disabled: isGuest,
            items: [
                {
                    title: 'Trade',
                    href: '/webull-trading',
                    icon: DollarSign,
                    disabled: isGuest,
                },
                {
                    title: 'List Positions',
                    href: '/webull-positions',
                    icon: List,
                    disabled: isGuest,
                },
                {
                    title: 'List Orders',
                    href: '/webull-orders',
                    icon: List,
                    disabled: isGuest,
                },
                {
                    title: 'View Transactions',
                    href: '/webull-transactions',
                    icon: List,
                    disabled: isGuest,
                },
                {
                    title: 'Upload Data',
                    href: '/upload-webull-data',
                    icon: Upload,
                    disabled: isGuest,
                },
            ],
        },
        {
            title: 'Alpaca',
            href: '/alpaca-orders',
            icon: DollarSign,
            disabled: isGuest,
            items: [
                {
                    title: 'Place Order',
                    href: '/alpaca-place-order',
                    icon: ShoppingCart,
                    disabled: isGuest,
                },
                {
                    title: 'Backtest vs Actual',
                    href: '/backtest-vs-actual',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Buy Slippage',
                    href: '/alpaca-buy-slippage',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Capital Invested',
                    href: '/alpaca-capital-invested',
                    icon: DollarSign,
                    disabled: isGuest,
                },
                {
                    title: 'Daily Performance',
                    href: '/alpaca-daily-performance',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'ML Threshold P/L',
                    href: '/analysis/ml-threshold-profit-loss',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Orders From API',
                    href: '/alpaca-orders-api',
                    icon: DollarSign,
                    disabled: isGuest,
                },
                {
                    title: 'P & L by Entry Time',
                    href: '/alpaca-pl-by-entry-time',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'P & L Calendar',
                    href: '/alpaca-calendar',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Sell Slippage',
                    href: '/alpaca-sell-slippage',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'View Orders',
                    href: '/alpaca-orders',
                    icon: List,
                    disabled: isGuest,
                },
            ],
        },
        {
            title: 'Training',
            href: '/training/analyze-trade-alerts',
            icon: GraduationCap,
            disabled: isGuest,
            items: [
                {
                    title: 'Analyze Trade Alerts',
                    href: '/training/analyze-trade-alerts',
                    icon: FileText,
                    disabled: isGuest,
                },
                {
                    title: 'Retrain Models',
                    href: '/training/retrain-models',
                    icon: History,
                    disabled: isGuest,
                },
                {
                    title: 'Rescore Alert',
                    href: '/training/rescore-alert',
                    icon: Target,
                    disabled: isGuest,
                },
            ],
        },
        {
            title: 'Orders',
            href: '/orders/buy',
            icon: ShoppingCart,
            hidden: true,
            disabled: isGuest,
            items: [
                {
                    title: 'Buy Order',
                    href: '/orders/buy',
                    icon: ShoppingCart,
                    disabled: isGuest,
                },
                {
                    title: 'Set Stop Loss',
                    href: '/orders/stop-loss',
                    icon: StopCircle,
                    disabled: isGuest,
                },
                {
                    title: 'Get Webull AcctId',
                    href: '/orders/webull-account',
                    icon: Key,
                    disabled: isGuest,
                },
            ],
        },
        {
            title: 'Watched',
            href: '/watches', // Default to the first submenu item
            icon: Eye,
            disabled: isGuest,
            items: [
                {
                    title: 'CSV Set Watches',
                    href: '/watches/csv',
                    icon: Upload,
                    disabled: isGuest,
                },
                {
                    title: 'My Hour',
                    href: '/my-hour',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Set Watches',
                    href: '/watches/settings',
                    icon: Eye,
                    disabled: isGuest,
                },
                {
                    title: 'View Watches',
                    href: '/watches',
                    icon: Eye,
                    disabled: isGuest,
                },
                {
                    title: 'Watched Analysis',
                    href: '/watched-analysis',
                    icon: Zap,
                    disabled: isGuest,
                },
            ],
        },
        {
            title: 'Analysis',
            href: '/notable-assets', // Default to the first submenu item
            icon: BarChart,
            disabled: isGuest,
            items: [
                {
                    title: '5-Min VWAP Status',
                    href: '/analysis/vwap-status',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Backtest TA Results',
                    href: '/backtest-results',
                    icon: Activity,
                    disabled: isGuest,
                },
                {
                    title: 'Best Gains 7 Days',
                    href: '/analysis/best-gains-7d',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Bottom Detect',
                    href: '/analysis/bottom-detect',
                    icon: TrendingDown,
                    disabled: isGuest,
                },
                {
                    title: 'Breakout',
                    href: '/analysis/breakout',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Breakout Confirmed',
                    href: '/analysis/breakout-confirmed',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Buy Predictor',
                    href: '/buy-predictor',
                    icon: Target,
                    disabled: isGuest,
                },
                {
                    title: 'Buy Signals',
                    href: '/buy-signals',
                    icon: Target,
                    disabled: isGuest,
                    openInNewTab: true,
                },
                {
                    title: 'Buy Window',
                    href: '/buy-window',
                    icon: Target,
                    disabled: isGuest,
                },
                {
                    title: 'Buy Zone Top Performers',
                    href: '/analysis/buy-zone-top-performers',
                    icon: Target,
                    disabled: isGuest,
                },
                {
                    title: 'Clean 2H Uptrend',
                    href: '/clean-2h',
                    icon: Target,
                    disabled: isGuest,
                },
                {
                    title: 'Daily Rising 100',
                    href: '/rising',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Gainers & Losers',
                    href: '/analysis/gainers-losers',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Good Long Buy',
                    href: '/analysis/good-long-buy',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Hybrid Momentum Scan',
                    href: '/hybrid-momentum-scan',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Last 4 Bars Up',
                    href: '/last-4-bars-up',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Notable Assets',
                    href: '/notable-assets',
                    icon: Zap,
                    disabled: isGuest,
                    openInNewTab: true,
                },
                {
                    title: 'Pipeline Counts',
                    href: '/analysis/pipeline-counts',
                    icon: BarChart,
                    disabled: isGuest,
                },
                {
                    title: 'Risers Not Topped',
                    href: '/risers-not-topped',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Rising In Hour',
                    href: '/rising-hour',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Rising Since Close',
                    href: '/analysis/rising-since-close',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Rising Stock Analysis',
                    href: '/check-top',
                    icon: TrendingDown,
                    disabled: isGuest,
                    openInNewTab: true,
                },
                {
                    title: 'Score Symbol',
                    href: '/analysis/score-symbol',
                    icon: Target,
                    disabled: isGuest,
                },
                {
                    title: 'Score Symbol List',
                    href: '/analysis/score-symbol-list',
                    icon: Target,
                    disabled: isGuest,
                },
                {
                    title: 'Sentiments',
                    href: '/sentiments',
                    icon: MessageSquare,
                    disabled: isGuest,
                },
                {
                    title: 'Upward Pressure',
                    href: '/analysis/upward-pressure',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
            ],
        },
        {
            title: 'TA Lab Analysis',
            href: '/ta-lib-analysis',
            icon: BarChart,
            disabled: isGuest,
            items: [
                {
                    title: 'Daily',
                    href: '/ta-lib-analysis',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: '5 Minute',
                    href: '/ta-lib-analysis/five-minute',
                    icon: Clock,
                    disabled: isGuest,
                },
                {
                    title: 'Valid Entry',
                    href: '/ta-lib-analysis/valid-entry',
                    icon: Clock,
                    disabled: isGuest,
                },
            ],
        },
        {
            title: 'Price Data',
            href: '/price-data/one-minute',
            icon: Database,
            disabled: isGuest,
            items: [
                {
                    title: 'One Minute',
                    href: '/price-data/one-minute',
                    icon: Database,
                    disabled: isGuest,
                },
                {
                    title: 'Five Minute',
                    href: '/price-data/five-minute',
                    icon: Database,
                    disabled: isGuest,
                },
                {
                    title: 'Daily',
                    href: '/price-data/daily',
                    icon: Database,
                    disabled: isGuest,
                },
                {
                    title: 'Latest Quotes',
                    href: '/price-data/latest-quotes',
                    icon: Database,
                    disabled: isGuest,
                },
            ],
        },
        {
            title: 'Market Regime',
            href: '/market-strength',
            icon: TrendingUp,
            disabled: isGuest,
            items: [
                {
                    title: 'Market Strength',
                    href: '/market-strength',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
                {
                    title: 'Market Movers',
                    href: '/market-movers',
                    icon: TrendingUp,
                    disabled: isGuest,
                },
            ],
        },
        {
            title: 'View Notifications',
            href: '/notifications',
            icon: Bell,
            disabled: isGuest,
        },
        {
            title: 'Set Notifications',
            href: '/notifications/settings',
            icon: Bell,
            disabled: isGuest,
        },
        ...(isAdmin
            ? [
                  {
                      title: 'System',
                      href: '/mysql-health', // Default to first submenu item
                      icon: Settings,
                      items: [
                          {
                              title: 'HTOP',
                              href: '/logs/htop',
                              icon: Activity,
                          },
                          {
                              title: 'CPU Temp',
                              href: '/logs/cpu-temp',
                              icon: Thermometer,
                          },
                          {
                              title: 'Temp Chart',
                              href: '/logs/temp-chart',
                              icon: Thermometer,
                          },
                          {
                              title: 'MySQL Health',
                              href: '/mysql-health',
                              icon: Database,
                          },
                          {
                              title: 'Pipeline Observability',
                              href: '/pipeline-observability',
                              icon: Activity,
                          },
                          {
                              title: 'Queue Monitor',
                              href: '/queue-monitor',
                              icon: Activity,
                          },
                          {
                              title: 'Processes Running',
                              href: '/processes-running',
                              icon: Activity,
                          },
                          {
                              title: 'Trading Settings',
                              href: editTradingSettings().url,
                              icon: Settings,
                          },
                          {
                              title: 'Trade Settings 2',
                              href: '/trading-settings-2',
                              icon: Settings,
                          },
                          {
                              title: 'Redis Keys',
                              href: '/redis-keys',
                              icon: Database,
                          },
                          {
                              title: 'Settings Snapshots',
                              href: '/settings-snapshots',
                              icon: Camera,
                          },
                      ],
                  },
                  {
                      title: 'Logs',
                      href: '/logs/laravel',
                      icon: FileText,
                      items: [
                          {
                              title: 'Continuous BT',
                              href: '/logs/continuous-bt',
                              icon: FileText,
                          },
                          {
                              title: 'Laravel',
                              href: '/logs/laravel',
                              icon: FileText,
                          },
                          {
                              title: 'Laravel Scheduler',
                              href: '/logs/scheduler',
                              icon: FileText,
                          },
                          {
                              title: 'Streaming Daemons',
                              href: '/logs/streaming',
                              icon: FileText,
                          },
                          {
                              title: 'Stale Entries',
                              href: '/logs/stale-entries',
                              icon: FileText,
                          },
                          {
                              title: 'Realtime Alerts',
                              href: '/logs/realtime-alerts',
                              icon: Activity,
                          },
                      ],
                  },
                  {
                      title: 'Alert Logs',
                      href: '/alert-logs',
                      icon: History,
                  },
              ]
            : []),
        {
            title: 'Investment Disclaimer',
            href: '/disclaimer',
            icon: Shield,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain
                    items={mainNavItems.filter((item) => !item.hidden)}
                    notificationCounts={notificationCounts}
                />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
