import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
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
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { index } from '@/routes/notifications';
import { show as showAsset } from '@/routes/asset-info';
import { destroyAll } from '@/actions/App/Http/Controllers/PriceAlertController';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, router, Link } from '@inertiajs/react';
import { Edit2, Eye, EyeOff, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface AssetInfo {
    id: number;
    symbol: string;
    common_name: string;
    current_price?: number | null;
}

interface PriceAlert {
    id: number;
    asset: AssetInfo;
    base_price: string;
    up_percentage: string;
    down_percentage: string;
    above_price: string;
    below_price: string;
    up_enabled: boolean;
    down_enabled: boolean;
}

interface Props {
    priceAlerts: PriceAlert[];
    watchedAssets: AssetInfo[];
    defaultUpPercentage: number;
    defaultDownPercentage: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'View Notifications',
        href: index.url(),
    },
    {
        title: 'Settings',
        href: '#',
    },
];

function PriceAlertForm({
    asset,
    existingAlert,
    onClose,
    defaultUpPercentage,
    defaultDownPercentage,
}: {
    asset: AssetInfo;
    existingAlert?: PriceAlert;
    onClose: () => void;
    defaultUpPercentage: number;
    defaultDownPercentage: number;
}) {
    const { data, setData, post, patch, processing, errors } = useForm({
        asset_info_id: asset.id,
        base_price:
            existingAlert?.base_price || asset.current_price?.toString() || '',
        up_percentage: existingAlert?.up_percentage || defaultUpPercentage.toString(),
        down_percentage: existingAlert?.down_percentage || defaultDownPercentage.toString(),
        up_enabled: existingAlert?.up_enabled ?? true,
        down_enabled: existingAlert?.down_enabled ?? true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (existingAlert) {
            patch(`/price-alerts/${existingAlert.id}`, {
                onSuccess: () => onClose(),
            });
        } else {
            post('/price-alerts', {
                onSuccess: () => onClose(),
            });
        }
    };

    const basePrice = parseFloat(data.base_price) || 0;
    const upPercent = parseFloat(data.up_percentage) || 0;
    const downPercent = parseFloat(data.down_percentage) || 0;
    const abovePrice = basePrice * (1 + upPercent / 100);
    const belowPrice = basePrice * (1 - downPercent / 100);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <Card className="w-full max-w-md">
                <CardHeader>
                    <CardTitle>
                        {existingAlert ? 'Edit' : 'Create'} Price Alert
                    </CardTitle>
                    <CardDescription>
                        <Link
                            href={showAsset.url(asset.id)}
                            className="text-blue-600 hover:text-blue-700 hover:underline font-medium dark:text-blue-400 dark:hover:text-blue-300"
                        >
                            {asset.symbol}
                        </Link>{' '}
                        - {asset.common_name}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={handleSubmit} className="space-y-6">
                        {/* Base Price */}
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                Base Price ($)
                            </label>
                            <Input
                                type="number"
                                step="0.01"
                                value={data.base_price}
                                onChange={(e) =>
                                    setData('base_price', e.target.value)
                                }
                                placeholder="100.00"
                                className={
                                    errors.base_price ? 'border-red-500' : ''
                                }
                            />
                            {errors.base_price && (
                                <p className="mt-1 text-sm text-red-500">
                                    {errors.base_price}
                                </p>
                            )}
                        </div>

                        {/* Up Alert Section */}
                        <div className="space-y-3 border-t pt-4">
                            <div className="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    id="up_enabled"
                                    checked={data.up_enabled}
                                    onChange={(e) =>
                                        setData('up_enabled', e.target.checked)
                                    }
                                    className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-2"
                                />
                                <label
                                    htmlFor="up_enabled"
                                    className="text-sm font-medium"
                                >
                                    Enable Up Alert
                                </label>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    Up Percentage (%)
                                </label>
                                <Input
                                    type="number"
                                    step="0.1"
                                    value={data.up_percentage}
                                    onChange={(e) =>
                                        setData('up_percentage', e.target.value)
                                    }
                                    placeholder="2.5"
                                    disabled={!data.up_enabled}
                                    className={
                                        errors.up_percentage
                                            ? 'border-red-500'
                                            : ''
                                    }
                                />
                                {errors.up_percentage && (
                                    <p className="mt-1 text-sm text-red-500">
                                        {errors.up_percentage}
                                    </p>
                                )}
                                {data.up_enabled &&
                                    basePrice &&
                                    upPercent > 0 && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Alert when price reaches: $
                                            {abovePrice.toFixed(2)}
                                        </p>
                                    )}
                            </div>
                        </div>

                        {/* Down Alert Section */}
                        <div className="space-y-3 border-t pt-4">
                            <div className="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    id="down_enabled"
                                    checked={data.down_enabled}
                                    onChange={(e) =>
                                        setData(
                                            'down_enabled',
                                            e.target.checked,
                                        )
                                    }
                                    className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-2"
                                />
                                <label
                                    htmlFor="down_enabled"
                                    className="text-sm font-medium"
                                >
                                    Enable Down Alert
                                </label>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    Down Percentage (%)
                                </label>
                                <Input
                                    type="number"
                                    step="0.1"
                                    value={data.down_percentage}
                                    onChange={(e) =>
                                        setData(
                                            'down_percentage',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="2.5"
                                    disabled={!data.down_enabled}
                                    className={
                                        errors.down_percentage
                                            ? 'border-red-500'
                                            : ''
                                    }
                                />
                                {errors.down_percentage && (
                                    <p className="mt-1 text-sm text-red-500">
                                        {errors.down_percentage}
                                    </p>
                                )}
                                {data.down_enabled &&
                                    basePrice &&
                                    downPercent > 0 && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Alert when price reaches: $
                                            {belowPrice.toFixed(2)}
                                        </p>
                                    )}
                            </div>
                        </div>

                        <div className="flex gap-2 pt-2">
                            <Button
                                type="submit"
                                disabled={processing}
                                className="flex-1"
                            >
                                {processing ? 'Saving...' : 'Save Alert'}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    );
}

function PriceAlertItem({ 
    alert, 
    defaultUpPercentage, 
    defaultDownPercentage 
}: { 
    alert: PriceAlert;
    defaultUpPercentage: number;
    defaultDownPercentage: number;
}) {
    const { delete: deleteAlert, patch: patchAlert } = useForm({});
    const [isEditing, setIsEditing] = useState(false);

    const handleToggle = () => {
        patchAlert(`/price-alerts/${alert.id}/toggle`);
    };

    const handleDelete = () => {
        deleteAlert(`/price-alerts/${alert.id}`);
    };

    const isActive = alert.up_enabled || alert.down_enabled;

    return (
        <Card className={!isActive ? 'opacity-50' : ''}>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <div className="flex-1">
                        <div className="flex items-center gap-2">
                            <CardTitle className="text-lg">
                                <Link
                                    href={showAsset.url(alert.asset.id)}
                                    className="text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    {alert.asset.symbol}
                                </Link>
                            </CardTitle>
                            <Badge variant="outline">
                                {alert.up_enabled && alert.down_enabled
                                    ? 'Both'
                                    : alert.up_enabled
                                      ? 'Up Only'
                                      : alert.down_enabled
                                        ? 'Down Only'
                                        : 'Inactive'}
                            </Badge>
                        </div>
                        <CardDescription>
                            {alert.asset.common_name}
                        </CardDescription>
                    </div>
                    <div className="flex gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={handleToggle}
                            title={isActive ? 'Disable alert' : 'Enable alert'}
                        >
                            {isActive ? (
                                <Eye className="h-4 w-4" />
                            ) : (
                                <EyeOff className="h-4 w-4" />
                            )}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setIsEditing(true)}
                        >
                            <Edit2 className="h-4 w-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={handleDelete}
                        >
                            <Trash2 className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <p className="mb-1 text-xs text-muted-foreground">
                            Base Price
                        </p>
                        <p className="font-semibold">
                            ${parseFloat(alert.base_price).toFixed(2)}
                        </p>
                    </div>
                    <div className="space-y-1">
                        <p className="text-xs text-muted-foreground">
                            Alert Prices
                        </p>
                        <p className="text-xs">
                            ↑ ${parseFloat(alert.above_price).toFixed(2)}
                        </p>
                        <p className="text-xs">
                            ↓ ${parseFloat(alert.below_price).toFixed(2)}
                        </p>
                    </div>
                    {alert.up_enabled && (
                        <div>
                            <p className="mb-1 text-xs text-muted-foreground">
                                Up Threshold
                            </p>
                            <p className="font-semibold">
                                {parseFloat(alert.up_percentage).toFixed(1)}%
                            </p>
                        </div>
                    )}
                    {alert.down_enabled && (
                        <div>
                            <p className="mb-1 text-xs text-muted-foreground">
                                Down Threshold
                            </p>
                            <p className="font-semibold">
                                {parseFloat(alert.down_percentage).toFixed(1)}%
                            </p>
                        </div>
                    )}
                </div>
            </CardContent>

            {isEditing && (
                <PriceAlertForm
                    asset={alert.asset}
                    existingAlert={alert}
                    onClose={() => setIsEditing(false)}
                    defaultUpPercentage={defaultUpPercentage}
                    defaultDownPercentage={defaultDownPercentage}
                />
            )}
        </Card>
    );
}

export default function NotificationsSettings({
    priceAlerts,
    watchedAssets,
    defaultUpPercentage,
    defaultDownPercentage,
}: Props) {
    const [showForm, setShowForm] = useState(false);
    const [selectedAsset, setSelectedAsset] = useState<AssetInfo | null>(null);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const { post } = useForm();

    const handleAddAlert = (asset: AssetInfo) => {
        setSelectedAsset(asset);
        setShowForm(true);
    };

    const handleAddAll = () => {
        post('/price-alerts/store-all', {
            onSuccess: () => {
                // The page will automatically refresh and show the new alerts
            },
        });
    };

    const handleDeleteAllAlerts = () => {
        setShowDeleteDialog(true);
    };

    const confirmDeleteAllAlerts = () => {
        router.delete(destroyAll.url(), {
            onSuccess: () => {
                // The page will automatically refresh with updated data
            },
        });
        setShowDeleteDialog(false);
    };

    const alertedAssets = new Set(priceAlerts.map((a) => a.asset.id));
    const availableAssets = watchedAssets.filter(
        (a) => !alertedAssets.has(a.id),
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification Settings" />
            <div className="mx-auto max-w-4xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                            Price Alerts
                        </h1>
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            Create notifications when stock prices reach your target
                            levels
                        </p>
                    </div>
                    {priceAlerts.length > 0 && (
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={handleDeleteAllAlerts}
                            className="gap-2"
                        >
                            <Trash2 className="h-4 w-4" />
                            Delete All Alerts ({priceAlerts.length})
                        </Button>
                    )}
                </div>

                {/* Price Alerts Section */}
                {priceAlerts.length > 0 && (
                    <div className="space-y-4">
                        <h2 className="text-xl font-semibold">
                            Your Price Alerts
                        </h2>
                        <div className="grid gap-4 md:grid-cols-2">
                            {priceAlerts.map((alert) => (
                                <PriceAlertItem 
                                    key={alert.id} 
                                    alert={alert}
                                    defaultUpPercentage={defaultUpPercentage}
                                    defaultDownPercentage={defaultDownPercentage}
                                />
                            ))}
                        </div>
                    </div>
                )}

                {/* Add New Alert Section */}
                {availableAssets.length > 0 && (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <h2 className="text-xl font-semibold">
                                Add Price Alert
                            </h2>
                            <Button
                                variant="outline" 
                                onClick={handleAddAll}
                                disabled={availableAssets.length === 0}
                                className="gap-2"
                            >
                                <Plus className="h-4 w-4" />
                                Add ALL ({availableAssets.length})
                            </Button>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {availableAssets.map((asset) => (
                                <Card
                                    key={asset.id}
                                    className="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50"
                                >
                                    <CardContent className="flex items-center justify-between p-4">
                                        <div>
                                            <p className="font-semibold">
                                                <Link
                                                    href={showAsset.url(asset.id)}
                                                    className="text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                >
                                                    {asset.symbol}
                                                </Link>
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {asset.common_name}
                                            </p>
                                            {asset.current_price && (
                                                <p className="mt-1 text-xs text-emerald-600 dark:text-emerald-400">
                                                    $
                                                    {asset.current_price.toFixed(
                                                        2,
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                handleAddAlert(asset)
                                            }
                                        >
                                            <Plus className="h-4 w-4" />
                                        </Button>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </div>
                )}

                {/* Empty State */}
                {priceAlerts.length === 0 && availableAssets.length === 0 && (
                    <Card className="py-12 text-center">
                        <CardContent className="flex flex-col items-center justify-center gap-4">
                            <div className="text-5xl">📊</div>
                            <div>
                                <CardTitle>No watched stocks</CardTitle>
                                <CardDescription className="mt-1">
                                    Add stocks to your watchlist first to create
                                    price alerts
                                </CardDescription>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {showForm && selectedAsset && (
                    <PriceAlertForm
                        asset={selectedAsset}
                        onClose={() => {
                            setShowForm(false);
                            setSelectedAsset(null);
                        }}
                        defaultUpPercentage={defaultUpPercentage}
                        defaultDownPercentage={defaultDownPercentage}
                    />
                )}

                {/* Delete All Confirmation Dialog */}
                <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <Trash2 className="h-5 w-5 text-red-600" />
                                Delete All Price Alerts
                            </DialogTitle>
                            <DialogDescription>
                                Are you sure you want to delete ALL price alerts? This will remove all your notification settings but keep your watched stocks.
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
                                onClick={confirmDeleteAllAlerts}
                                className="flex items-center gap-2"
                            >
                                <Trash2 className="h-4 w-4" />
                                Delete All
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
