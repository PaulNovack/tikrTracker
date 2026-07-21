import React, { useEffect, useState } from 'react';
// Remove useEcho import and use direct Echo API
// import { useEcho } from '@laravel/echo-react';

interface TradeAlert {
    symbol: string;
    asset_type: string;
    signal_type: string;
    entry_type: string;
    entry: number | string;
    stop: number | string;
    risk_pct: number | string;
    score: number | string;
    targets: {
        '1R': number | string;
        '2R': number | string;
        '3R': number | string;
    };
    signal_ts_est: string;
    entry_ts_est: string;
    created_at: string;
}

interface TradeAlertNotificationProps {
    onAlertReceived?: (alert: TradeAlert) => void;
}

export function TradeAlertNotification({ onAlertReceived }: TradeAlertNotificationProps) {
    const [alerts, setAlerts] = useState<TradeAlert[]>([]);
    const [showToast, setShowToast] = useState(false);
    const [latestAlert, setLatestAlert] = useState<TradeAlert | null>(null);

    // Use direct Echo API instead of useEcho hook
    useEffect(() => {
        // Get the Echo instance
        const echo = (window as any).Echo;
        
        if (!echo) {
            console.warn('Echo not available');
            return;
        }

        console.log('Setting up direct Echo subscription to trade-alerts channel');
        console.log('Echo instance:', echo);
        console.log('Echo connector:', echo.connector);
        
        // Subscribe to public channel directly using pusher syntax
        const channel = echo.channel('trade-alerts');
        console.log('Channel created:', channel);
        console.log('Channel methods:', Object.getOwnPropertyNames(channel));
        
        const handleTradeAlert = (data: TradeAlert) => {
            console.log('🚨 NEW TRADE ALERT RECEIVED:', data);
            
            setLatestAlert(data);
            setAlerts(prev => [data, ...prev.slice(0, 9)]); // Keep last 10 alerts
            setShowToast(true);
            
            // Auto-hide toast after 60 seconds (full minute for thorough review)
            setTimeout(() => setShowToast(false), 60000);
            
            // Call optional callback
            onAlertReceived?.(data);
            
            // Show browser notification if permission granted
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(`🚨 Trade Alert: ${data.symbol}`, {
                    body: `${data.entry_type} - Entry: $${data.entry} | Stop: $${data.stop} | Risk: ${data.risk_pct}%`,
                    icon: '/favicon.ico',
                    tag: `trade-alert-${data.symbol}`,
                });
            }
        };

        // Try different event listeners - the event might be coming as different format
        console.log('Setting up event listeners...');
        
        // The correct event name based on global listener feedback
        channel.listen('alert.created', (data: any) => {
            console.log('📡 Received via alert.created:', data);
            handleTradeAlert(data);
        });

        // Also try binding directly to the pusher subscription
        if (channel.subscription) {
            channel.subscription.bind('alert.created', (data: any) => {
                console.log('📡 Received via pusher bind:', data);
                handleTradeAlert(data);
            });
        }

        // Also listen to the pusher channel directly to debug
        if (channel.subscription) {
            channel.subscription.bind_global((eventName: string, data: any) => {
                console.log('🔍 Global pusher event received:', eventName, data);
            });
        }

        console.log('✅ All event listeners set up');

        // Cleanup function
        return () => {
            console.log('Cleaning up Echo subscription');
            if (channel) {
                channel.stopListening('alert.created');
                if (channel.subscription) {
                    channel.subscription.unbind('alert.created');
                }
                echo.leaveChannel('trade-alerts');
            }
        };
    }, [onAlertReceived]);

    useEffect(() => {
        // Request notification permission on mount
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }, []);

    const formatTime = (timestamp: string) => {
        return new Date(timestamp).toLocaleTimeString('en-US', {
            timeZone: 'America/New_York',
            hour12: true,
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit'
        });
    };

    const formatEstTime = (timestamp: string) => {
        // For timestamps that are already in EST (like signal_ts_est, entry_ts_est)
        // Don't apply timezone conversion, just format them as-is
        return new Date(timestamp + 'Z').toLocaleTimeString('en-US', {
            timeZone: 'UTC', // Use UTC to avoid double conversion
            hour12: true,
            hour: 'numeric',
            minute: '2-digit'
        });
    };

    const formatPrice = (price: any): string => {
        if (price === null || price === undefined) {
            return '$0.00';
        }
        
        const numericPrice = typeof price === 'number' ? price : parseFloat(String(price));
        
        if (isNaN(numericPrice)) {
            return '$0.00';
        }
        
        return `$${numericPrice.toFixed(2)}`;
    };

    const formatPercent = (value: any): string => {
        if (value === null || value === undefined) {
            return '0.0%';
        }
        
        const numericValue = typeof value === 'number' ? value : parseFloat(String(value));
        
        if (isNaN(numericValue)) {
            return '0.0%';
        }
        
        return `${numericValue.toFixed(1)}%`;
    };

    return (
        <>
            {/* Toast Notification */}
            {showToast && latestAlert && (
                <div className="fixed top-4 right-4 z-50 max-w-md w-full bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 animate-slide-in">
                    <div className="p-4">
                        <div className="flex items-start">
                            <div className="flex-shrink-0">
                                <div className="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center">
                                    <span className="text-green-600 dark:text-green-400 text-sm font-bold">📈</span>
                                </div>
                            </div>
                            <div className="ml-3 w-0 flex-1">
                                <div className="flex items-center justify-between">
                                    <p className="text-sm font-bold text-gray-900 dark:text-gray-100">
                                        {latestAlert.symbol} • {latestAlert.asset_type.toUpperCase()}
                                    </p>
                                    <span className="text-xs text-gray-400 dark:text-gray-500">
                                        {formatTime(latestAlert.created_at)}
                                    </span>
                                </div>
                                
                                <p className="mt-1 text-xs text-blue-600 dark:text-blue-400 font-medium">
                                    {latestAlert.signal_type} → {latestAlert.entry_type}
                                </p>
                                
                                <div className="mt-2 grid grid-cols-2 gap-2 text-xs">
                                    <div>
                                        <span className="text-gray-500 dark:text-gray-400">Entry:</span>
                                        <span className="ml-1 font-medium text-gray-900 dark:text-gray-100">{formatPrice(latestAlert.entry)}</span>
                                    </div>
                                    <div>
                                        <span className="text-gray-500 dark:text-gray-400">Stop:</span>
                                        <span className="ml-1 font-medium text-gray-900 dark:text-gray-100">{formatPrice(latestAlert.stop)}</span>
                                    </div>
                                    <div>
                                        <span className="text-gray-500 dark:text-gray-400">Risk:</span>
                                        <span className="ml-1 font-medium text-orange-600 dark:text-orange-400">{parseFloat(latestAlert.risk_pct).toFixed(2)}%</span>
                                    </div>
                                    <div>
                                        <span className="text-gray-500 dark:text-gray-400">Score:</span>
                                        <span className="ml-1 font-medium text-green-600 dark:text-green-400">{parseFloat(latestAlert.score).toFixed(2)}</span>
                                    </div>
                                </div>
                                
                                {latestAlert.targets && (latestAlert.targets['1R'] || latestAlert.targets['2R'] || latestAlert.targets['3R']) && (
                                    <div className="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <div className="flex gap-3 text-xs">
                                            {latestAlert.targets['1R'] && (
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">1R:</span>
                                                    <span className="ml-1 font-medium text-green-600 dark:text-green-400">{formatPrice(latestAlert.targets['1R'])}</span>
                                                </div>
                                            )}
                                            {latestAlert.targets['2R'] && (
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">2R:</span>
                                                    <span className="ml-1 font-medium text-green-600 dark:text-green-400">{formatPrice(latestAlert.targets['2R'])}</span>
                                                </div>
                                            )}
                                            {latestAlert.targets['3R'] && (
                                                <div>
                                                    <span className="text-gray-500 dark:text-gray-400">3R:</span>
                                                    <span className="ml-1 font-medium text-green-600 dark:text-green-400">{formatPrice(latestAlert.targets['3R'])}</span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}
                                
                                <div className="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                    Signal: {formatEstTime(latestAlert.signal_ts_est)} EST
                                </div>
                            </div>
                            <div className="ml-4 flex-shrink-0 flex">
                                <button
                                    className="bg-white dark:bg-gray-800 rounded-md inline-flex text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400"
                                    onClick={() => setShowToast(false)}
                                >
                                    <span className="sr-only">Close</span>
                                    <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

export default TradeAlertNotification;