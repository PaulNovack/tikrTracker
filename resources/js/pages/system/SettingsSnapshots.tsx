import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Camera, ChevronDown, ChevronRight, History, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    { title: 'Settings Snapshots', href: '/settings-snapshots' },
];

interface Snapshot {
    id: number;
    name: string;
    key_count: number;
    created_at: string;
    updated_at: string;
}

interface Props {
    snapshots: Snapshot[];
    flash?: { success?: string };
}

export default function SettingsSnapshots({ snapshots }: Props) {
    const { flash } = usePage().props as { flash?: { success?: string } };
    const [dialogOpen, setDialogOpen] = useState(false);
    const [restoringId, setRestoringId] = useState<number | null>(null);
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [snapshotKeys, setSnapshotKeys] = useState<Record<string, string> | null>(null);
    const [loadingKeys, setLoadingKeys] = useState(false);

    const createForm = useForm({ name: '' });

    const handleCreate = () => {
        createForm.post('/settings-snapshots', {
            onSuccess: () => {
                setDialogOpen(false);
                createForm.reset();
            },
        });
    };

    const handleRestore = (snapshot: Snapshot) => {
        if (!confirm(`Restore snapshot "${snapshot.name}"? This will overwrite ALL current settings.`)) return;

        setRestoringId(snapshot.id);
        router.post(`/settings-snapshots/${snapshot.id}/restore`, {}, {
            onFinish: () => setRestoringId(null),
        });
    };

    const handleDelete = (snapshot: Snapshot) => {
        if (!confirm(`Delete snapshot "${snapshot.name}"?`)) return;

        router.delete(`/settings-snapshots/${snapshot.id}`);
    };

    const handleToggleExpand = async (snapshot: Snapshot) => {
        if (expandedId === snapshot.id) {
            setExpandedId(null);
            setSnapshotKeys(null);
            return;
        }

        setExpandedId(snapshot.id);
        setLoadingKeys(true);

        try {
            const res = await fetch(`/settings-snapshots/${snapshot.id}`);
            const data = await res.json();
            setSnapshotKeys(data.keys);
        } catch {
            setSnapshotKeys(null);
        } finally {
            setLoadingKeys(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Settings Snapshots" />

            <div className="space-y-6 p-6">
                {flash?.success && (
                    <div className="rounded-md bg-green-50 p-4 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                <div className="flex items-center justify-between">
                    <HeadingSmall
                        title="Settings Snapshots"
                        description={`${snapshots.length} snapshot(s) — Save and restore all trading settings at once`}
                    />

                    <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                        <DialogTrigger asChild>
                            <Button>
                                <Camera className="mr-2 h-4 w-4" />
                                Create Snapshot
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Create Settings Snapshot</DialogTitle>
                                <DialogDescription>
                                    Save all current trading settings under a name you choose.
                                    You can restore them later.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-3 py-3">
                                <Label htmlFor="snapshot-name">Snapshot Name</Label>
                                <Input
                                    id="snapshot-name"
                                    placeholder="e.g. Before ML threshold changes"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleCreate()}
                                />
                                {createForm.errors.name && (
                                    <p className="text-sm text-red-500">{createForm.errors.name}</p>
                                )}
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={() => setDialogOpen(false)}>
                                    Cancel
                                </Button>
                                <Button onClick={handleCreate} disabled={createForm.processing || !createForm.data.name.trim()}>
                                    {createForm.processing ? 'Creating…' : 'Create Snapshot'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>

                {snapshots.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-gray-300 p-12 text-center dark:border-gray-600">
                        <History className="mx-auto mb-3 h-10 w-10 text-gray-300 dark:text-gray-600" />
                        <p className="text-sm text-gray-500 dark:text-gray-400">No snapshots yet.</p>
                        <p className="text-xs text-gray-400 dark:text-gray-500">
                            Create one to save all current trading settings.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-200 dark:border-gray-700">
                                    <th className="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Name</th>
                                    <th className="px-4 py-3 text-center font-medium text-gray-500 dark:text-gray-400">Keys</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Created</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Updated</th>
                                    <th className="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {snapshots.map((s) => (
                                    <>
                                        <tr
                                            key={s.id}
                                            className="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50"
                                            onClick={() => handleToggleExpand(s)}
                                        >
                                            <td className="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">
                                                <span className="inline-flex items-center gap-2">
                                                    {expandedId === s.id ? (
                                                        <ChevronDown className="h-4 w-4 text-gray-400" />
                                                    ) : (
                                                        <ChevronRight className="h-4 w-4 text-gray-400" />
                                                    )}
                                                    {s.name}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-center text-gray-500 dark:text-gray-400">
                                                {s.key_count}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                                {new Date(s.created_at).toLocaleString([], {
                                                    month: 'short',
                                                    day: 'numeric',
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                                {new Date(s.updated_at).toLocaleString([], {
                                                    month: 'short',
                                                    day: 'numeric',
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2" onClick={(e) => e.stopPropagation()}>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handleRestore(s)}
                                                        disabled={restoringId === s.id}
                                                    >
                                                        <RotateCcw className="mr-1 h-3.5 w-3.5" />
                                                        {restoringId === s.id ? 'Restoring…' : 'Restore'}
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleDelete(s)}
                                                        className="text-red-500 hover:text-red-700"
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                        {expandedId === s.id && (
                                            <tr key={`${s.id}-keys`} className="bg-gray-50 dark:bg-gray-800/30">
                                                <td colSpan={5} className="px-4 py-3">
                                                    {loadingKeys ? (
                                                        <p className="py-4 text-center text-sm text-gray-400">Loading keys…</p>
                                                    ) : snapshotKeys && Object.keys(snapshotKeys).length > 0 ? (
                                                        <div className="max-h-96 overflow-y-auto">
                                                            <table className="w-full text-xs">
                                                                <thead>
                                                                    <tr className="border-b border-gray-200 dark:border-gray-700">
                                                                        <th className="pb-2 text-left font-medium text-gray-500 dark:text-gray-400 w-2/5">Key</th>
                                                                        <th className="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Value</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                                                    {Object.entries(snapshotKeys).map(([key, value]) => (
                                                                        <tr key={key}>
                                                                            <td className="py-1.5 pr-3 font-mono text-gray-700 dark:text-gray-300 break-all">{key}</td>
                                                                            <td className="py-1.5 font-mono text-gray-500 dark:text-gray-400 break-all">{value}</td>
                                                                        </tr>
                                                                    ))}
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    ) : (
                                                        <p className="py-4 text-center text-sm text-gray-400">No keys found.</p>
                                                    )}
                                                </td>
                                            </tr>
                                        )}
                                    </>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
