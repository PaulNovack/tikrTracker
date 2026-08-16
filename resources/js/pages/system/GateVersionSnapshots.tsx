import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { type BreadcrumbItem } from '@/types';
import { Trash2, RotateCcw, History, ArrowLeft, Camera } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/generic-ta-gate-versions/snapshots' },
    { title: 'Gate Snapshots', href: '/generic-ta-gate-versions/snapshots' },
];

interface Snapshot {
    id: number;
    snapshot_name: string;
    pipeline_letter: string;
    version_string: string;
    signal_type: string | null;
    scanner_score_formula: string | null;
    enabled: boolean;
    gates_data: string;
    snapshot_at: string;
}

interface Props {
    snapshots: Snapshot[];
}

interface ParsedGate {
    gate_name: string;
    timeframe: string;
    threshold_min: number | null;
    threshold_max: number | null;
    enabled: boolean;
}

export default function GateVersionSnapshots({ snapshots }: Props) {
    const [viewing, setViewing] = useState<Snapshot | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const [status, setStatus] = useState<string | null>(null);

    const csrfToken = (): string =>
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    const restore = async (snapshot: Snapshot) => {
        if (!confirm(`Restore ${snapshot.pipeline_letter}/${snapshot.version_string} from snapshot "${snapshot.snapshot_name}"?\n\nThis will overwrite the current gates for that version.`)) {
            return;
        }
        setBusy(snapshot.id);
        setStatus(null);
        try {
            const res = await fetch(`/generic-ta-gate-versions/snapshots/${snapshot.id}/restore`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            });
            const data = await res.json();
            if (res.ok && data.success) {
                setStatus(`✅ Restored "${data.snapshot_name}" → ${data.pipeline_letter}/${data.version_string}`);
            } else {
                setStatus(`❌ ${data.error ?? 'Restore failed'}`);
            }
        } catch {
            setStatus('❌ Network error during restore');
        } finally {
            setBusy(null);
        }
    };

    const remove = async (snapshot: Snapshot) => {
        if (!confirm(`Delete snapshot "${snapshot.snapshot_name}"?`)) return;
        try {
            const res = await fetch(`/generic-ta-gate-versions/snapshots/${snapshot.id}`, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            });
            if (res.ok) {
                setStatus(`🗑️ Deleted "${snapshot.snapshot_name}"`);
                router.reload({ preserveScroll: true });
            }
        } catch {
            setStatus('❌ Network error during delete');
        }
    };

    const snapshotAll = async () => {
        setBusy(-1);
        setStatus(null);
        try {
            const res = await fetch('/generic-ta-gate-versions/snapshot-all', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            });
            const data = await res.json();
            if (res.ok && data.success) {
                setStatus(`✅ Created snapshots for ${data.created} version(s) (${data.stamp})`);
                router.reload({ preserveScroll: true });
            } else {
                setStatus(`❌ ${data.error ?? 'Failed to snapshot all'}`);
            }
        } catch {
            setStatus('❌ Network error while snapshotting all');
        } finally {
            setBusy(null);
        }
    };

    const formatTime = (ts: string) => new Date(ts).toLocaleString();

    const parsedGates = (snapshot: Snapshot): ParsedGate[] => {
        try {
            return JSON.parse(snapshot.gates_data) as ParsedGate[];
        } catch {
            return [];
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Gate Version Snapshots" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <HeadingSmall title="Gate Version Snapshots" description="View, restore, or delete point-in-time snapshots of alert version configurations." />
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/generic-ta-gate-versions"><ArrowLeft className="mr-1 h-4 w-4" /> Back to Versions</Link>
                        </Button>
                        <Button size="sm" onClick={snapshotAll} disabled={busy === -1}>
                            <Camera className={`mr-1 h-4 w-4 ${busy === -1 ? 'animate-pulse' : ''}`} />
                            {busy === -1 ? 'Snapshotting...' : 'Snapshot All'}
                        </Button>
                    </div>
                </div>
                <Separator />

                {status && (
                    <div className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200">
                        {status}
                    </div>
                )}

                {snapshots.length === 0 ? (
                    <div className="rounded-lg border bg-white px-4 py-8 text-center text-sm text-gray-500 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-400">
                        No snapshots yet. Use "Snapshot All" here, or snapshot individual versions from the version management page.
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border bg-white dark:bg-gray-900 dark:border-gray-700">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-gray-50 dark:border-gray-700 dark:bg-gray-800">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Snapshot Name</th>
                                    <th className="px-3 py-2 font-medium">Pipeline</th>
                                    <th className="px-3 py-2 font-medium">Version</th>
                                    <th className="px-3 py-2 font-medium">Taken At</th>
                                    <th className="px-3 py-2 font-medium">Gates</th>
                                    <th className="px-3 py-2 font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y dark:divide-gray-700">
                                {snapshots.map((s) => (
                                    <tr key={s.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                        <td className="px-3 py-2 font-mono text-xs">{s.snapshot_name}</td>
                                        <td className="px-3 py-2 font-semibold text-blue-600 dark:text-blue-400">{s.pipeline_letter}</td>
                                        <td className="px-3 py-2 font-mono text-xs">{s.version_string}</td>
                                        <td className="px-3 py-2 text-xs">{formatTime(s.snapshot_at)}</td>
                                        <td className="px-3 py-2 text-xs">{parsedGates(s).length} gates</td>
                                        <td className="px-3 py-2">
                                            <div className="flex gap-1">
                                                <Button variant="outline" size="sm" onClick={() => setViewing(s)}><History className="mr-1 h-3.5 w-3.5" /> View</Button>
                                                <Button size="sm" disabled={busy === s.id} onClick={() => restore(s)}>
                                                    <RotateCcw className="mr-1 h-3.5 w-3.5" /> {busy === s.id ? 'Restoring...' : 'Restore'}
                                                </Button>
                                                <Button variant="destructive" size="sm" onClick={() => remove(s)}><Trash2 className="h-3.5 w-3.5" /></Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* View snapshot dialog */}
                <Dialog open={viewing !== null} onOpenChange={(open) => { if (!open) setViewing(null); }}>
                    <DialogContent className="max-w-2xl">
                        <DialogHeader>
                            <DialogTitle className="font-mono text-sm">{viewing?.snapshot_name}</DialogTitle>
                            <DialogDescription>
                                {viewing ? `${viewing.pipeline_letter}/${viewing.version_string} — ${parsedGates(viewing).length} gates` : ''}
                            </DialogDescription>
                        </DialogHeader>
                        {viewing && (
                            <div className="max-h-96 overflow-y-auto rounded border p-3 dark:border-gray-700">
                                <div className="space-y-3">
                                    <div className="grid grid-cols-2 gap-2 text-xs">
                                        <div><Label className="text-gray-500">Signal Type</Label><div>{viewing.signal_type ?? '—'}</div></div>
                                        <div><Label className="text-gray-500">Score Formula</Label><div className="font-mono">{viewing.scanner_score_formula ?? '—'}</div></div>
                                        <div><Label className="text-gray-500">Enabled</Label><div>{viewing.enabled ? 'Yes' : 'No'}</div></div>
                                        <div><Label className="text-gray-500">Taken At</Label><div>{formatTime(viewing.snapshot_at)}</div></div>
                                    </div>
                                    <Separator />
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <h4 className="mb-1 text-xs font-semibold text-yellow-600 dark:text-yellow-400">5-Minute Gates</h4>
                                            <GateSnapshotList gates={parsedGates(viewing).filter((g) => g.timeframe === '5m')} />
                                        </div>
                                        <div>
                                            <h4 className="mb-1 text-xs font-semibold text-cyan-600 dark:text-cyan-400">1-Minute Gates</h4>
                                            <GateSnapshotList gates={parsedGates(viewing).filter((g) => g.timeframe === '1m')} />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}
                        <DialogFooter className="gap-2">
                            <Button variant="outline" onClick={() => setViewing(null)}>Close</Button>
                            {viewing && (
                                <Button disabled={busy === viewing.id} onClick={() => { restore(viewing); setViewing(null); }}>
                                    <RotateCcw className="mr-1 h-4 w-4" /> Restore This Snapshot
                                </Button>
                            )}
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}

/** Read-only list of gates within a snapshot view. */
function GateSnapshotList({ gates }: { gates: ParsedGate[] }) {
    if (gates.length === 0) {
        return <div className="text-xs text-gray-400">No gates</div>;
    }
    return (
        <div className="space-y-0.5">
            {gates.map((g) => (
                <div key={g.gate_name} className={`flex items-center justify-between rounded px-1 py-0.5 text-xs ${g.enabled ? '' : 'opacity-40'}`}>
                    <span className="font-mono">{g.gate_name}</span>
                    <span className="font-mono text-gray-500 dark:text-gray-400">
                        {g.enabled
                            ? `${g.threshold_min !== null ? g.threshold_min : '∞'} → ${g.threshold_max !== null ? g.threshold_max : '∞'}`
                            : 'disabled'}
                    </span>
                </div>
            ))}
        </div>
    );
}
