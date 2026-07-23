import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { destroy, markAllAsRead, markAsRead, deleteAll } from '@/routes/notifications';
import { show as showAsset } from '@/routes/asset-info';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertCircle, Bell, CheckCircle2, Info, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Notification {
    id: number;
    title: string;
    description: string;
    type: 'info' | 'success' | 'warning' | 'error';
    read: boolean;
    created_at: string;
    read_at: string | null;
    asset?: {
        id: number;
        symbol: string;
        common_name?: string;
        asset_type: 'stock';
    };
}

interface PaginatedNotifications {
    data: Notification[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
}

// Helper function to extract symbol from notification text and make it clickable
function makeSymbolsClickable(text: string, notification?: Notification) {
    // If notification has asset info, prioritize direct asset link
    if (notification?.asset) {
        const symbol = notification.asset.symbol;
        const symbolPattern = new RegExp(`\\b(${symbol})\\b`, 'g');
        const parts = [];
        let lastIndex = 0;
        let match;

        while ((match = symbolPattern.exec(text)) !== null) {
            // Add text before the symbol
            if (match.index > lastIndex) {
                parts.push(text.slice(lastIndex, match.index));
            }

            // Add the clickable symbol with direct asset link
            parts.push(
                <a
                    key={`asset-${notification.asset.id}-${match.index}`}
                    href={showAsset.url(notification.asset.id)}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-200 underline font-medium"
                    onClick={(e) => {
                        e.stopPropagation();
                    }}
                >
                    {symbol}
                </a>
            );

            lastIndex = match.index + match[0].length;
        }

        // Add remaining text
        if (lastIndex < text.length) {
            parts.push(text.slice(lastIndex));
        }

        return parts.length > 0 ? parts : text;
    }

    // Fallback: Pattern to match stock symbols (3-5 uppercase letters) with search
    const symbolPattern = /\b([A-Z]{2,5})\b/g;
    const parts = [];
    let lastIndex = 0;
    let match;

    while ((match = symbolPattern.exec(text)) !== null) {
        // Add text before the symbol
        if (match.index > lastIndex) {
            parts.push(text.slice(lastIndex, match.index));
        }

        // Add the clickable symbol with search fallback
        const symbol = match[1];
        parts.push(
            <a
                key={`symbol-${symbol}-${match.index}`}
                href={`/market-data/assets?filter=all&search=${symbol}`}
                target="_blank"
                rel="noopener noreferrer"
                className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-200 underline font-medium"
                onClick={(e) => {
                    e.stopPropagation();
                }}
            >
                {symbol}
            </a>
        );

        lastIndex = symbolPattern.lastIndex;
    }

    // Add remaining text after the last symbol
    if (lastIndex < text.length) {
        parts.push(text.slice(lastIndex));
    }

    return parts.length > 0 ? parts : text;
}

function getIcon(type: string) {
    switch (type) {
        case 'success':
            return <CheckCircle2 className="h-5 w-5 text-green-600" />;
        case 'warning':
            return <AlertCircle className="h-5 w-5 text-yellow-600" />;
        case 'error':
            return <AlertCircle className="h-5 w-5 text-red-600" />;
        case 'info':
        default:
            return <Info className="h-5 w-5 text-blue-600" />;
    }
}

export default function NotificationsPage() {
    const { notifications: notificationsFromServer } = usePage().props;
    
    // Handle both old array format and new paginated format for backward compatibility
    let notificationsData: Notification[] = [];
    let paginationData: Partial<PaginatedNotifications> = {};
    
    if (Array.isArray(notificationsFromServer)) {
        // Legacy format - array of notifications
        notificationsData = notificationsFromServer as Notification[];
    } else if (notificationsFromServer && typeof notificationsFromServer === 'object' && 'data' in notificationsFromServer) {
        // New paginated format
        const paginated = notificationsFromServer as PaginatedNotifications;
        notificationsData = paginated.data || [];
        paginationData = paginated;
    } else {
        // Fallback - treat as empty
        notificationsData = [];
    }

    const unreadCount = notificationsData.filter((n) => !n.read).length;

    // Dialog state for delete confirmation
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);

    // Auto-refresh every 30 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ only: ['notifications'], preserveState: true, preserveScroll: true });
        }, 30000);

        return () => clearInterval(interval);
    }, []);

    const handleMarkAllAsRead = () => {
        router.post(
            markAllAsRead().url,
            {},
            {
                preserveScroll: true,
            },
        );
    };

    const handleMarkAsRead = (notification: Notification) => {
        if (!notification.read) {
            router.post(
                markAsRead(notification).url,
                {},
                {
                    preserveScroll: true,
                },
            );
        }
    };

    const handleDelete = (notification: Notification) => {
        router.delete(destroy(notification).url, {
            preserveScroll: true,
        });
    };

    const handleDeleteAll = () => {
        setShowDeleteDialog(true);
    };

    const confirmDeleteAll = () => {
        router.delete(deleteAll().url, {
            preserveScroll: true,
        });
        setShowDeleteDialog(false);
    };

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Notifications', href: '/notifications' }]}
        >
            <Head title="Notifications" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Notifications"
                        description={
                            notificationsData.length === 0 
                                ? "Stay updated with your latest alerts and activity"
                                : `You have ${unreadCount} unread notification${unreadCount !== 1 ? 's' : ''}`
                        }
                    />
                    <div className="flex gap-2">
                        {unreadCount > 0 && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleMarkAllAsRead}
                            >
                                Mark all as read
                            </Button>
                        )}
                        {notificationsData.length > 0 && (
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={handleDeleteAll}
                                className="flex items-center gap-2"
                            >
                                <Trash2 className="h-4 w-4" />
                                Delete All
                            </Button>
                        )}
                    </div>
                </div>

                <div className="space-y-4">
                    {notificationsData.length === 0 ? (
                        <Card className="py-12 text-center">
                            <CardContent className="flex flex-col items-center justify-center gap-4">
                                <Bell className="h-12 w-12 text-gray-300" />
                                <div>
                                    <CardTitle className="text-base">
                                        No notifications
                                    </CardTitle>
                                    <CardDescription className="mt-1">
                                        You're all caught up! Check back later
                                        for updates.
                                    </CardDescription>
                                </div>
                            </CardContent>
                        </Card>
                    ) : (
                        notificationsData.map((notification) => (
                            <Card
                                key={notification.id}
                                className={
                                    !notification.read
                                        ? 'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/30'
                                        : ''
                                }
                            >
                                <CardContent className="pt-6">
                                    <div className="flex gap-4">
                                        <div className="flex-shrink-0">
                                            {getIcon(notification.type)}
                                        </div>
                                        <div className="flex-1">
                                            <div className="flex items-start justify-between">
                                                <div>
                                                    <CardTitle className="text-base">
                                                        {makeSymbolsClickable(notification.title, notification)}
                                                    </CardTitle>
                                                    <CardDescription className="mt-1">
                                                        <div dangerouslySetInnerHTML={{ __html: notification.description }} />
                                                    </CardDescription>
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        {new Date(
                                                            notification.created_at,
                                                        ).toLocaleString()}
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    {!notification.read && (
                                                        <>
                                                            <div className="h-3 w-3 flex-shrink-0 rounded-full bg-blue-600" />
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    handleMarkAsRead(
                                                                        notification,
                                                                    )
                                                                }
                                                            >
                                                                Mark as read
                                                            </Button>
                                                        </>
                                                    )}
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            handleDelete(
                                                                notification,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>

                {/* Pagination */}
                {notificationsData.length > 0 && paginationData.links && paginationData.links.length > 3 && (
                    <div className="flex justify-center">
                        <nav className="flex items-center gap-1" aria-label="Pagination">
                            {paginationData.links.map((link, index) => {
                                if (!link.url && link.label.includes('Previous')) {
                                    return (
                                        <Button
                                            key={index}
                                            variant="outline"
                                            size="sm"
                                            disabled
                                            className="opacity-50"
                                        >
                                            Previous
                                        </Button>
                                    );
                                }
                                
                                if (!link.url && link.label.includes('Next')) {
                                    return (
                                        <Button
                                            key={index}
                                            variant="outline"
                                            size="sm"
                                            disabled
                                            className="opacity-50"
                                        >
                                            Next
                                        </Button>
                                    );
                                }

                                if (link.url) {
                                    return (
                                        <Button
                                            key={index}
                                            variant={link.active ? "default" : "outline"}
                                            size="sm"
                                            onClick={() => router.visit(link.url!)}
                                        >
                                            {link.label.replace('&laquo;', '').replace('&raquo;', '').trim() || (link.label.includes('Previous') ? 'Previous' : 'Next')}
                                        </Button>
                                    );
                                }

                                return null;
                            })}
                        </nav>
                    </div>
                )}

                {/* Pagination info */}
                {notificationsData.length > 0 && paginationData.total && (
                    <div className="text-center text-sm text-muted-foreground">
                        Showing {paginationData.from} to {paginationData.to} of {paginationData.total} notifications
                    </div>
                )}
            </div>

            {/* Delete All Confirmation Dialog */}
            <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Trash2 className="h-5 w-5 text-red-600" />
                            Delete All Notifications
                        </DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete all notifications? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowDeleteDialog(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={confirmDeleteAll}
                            className="flex items-center gap-2"
                        >
                            <Trash2 className="h-4 w-4" />
                            Delete All
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
