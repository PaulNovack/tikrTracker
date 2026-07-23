import { edit, update, updateOther } from '@/actions/App/Http/Controllers/TradingSettings2Controller';
import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, Eye, EyeOff, ShieldAlert } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    { title: 'Trade Settings 2', href: edit().url },
];

type Props = {
    isPaperTrading: boolean;
    credentials: Record<string, string>;
    scorerScripts: Record<string, string>;
    modelPaths: Record<string, string>;
    pipelineDisplayNames: Record<string, string>;
    threeWhiteSoldiersScanEnabled: boolean;
    newsLink: string;
};

function SavedBadge({ show }: { show: boolean }) {
    if (!show) {
        return null;
    }
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
            <CheckCircle2 className="h-3 w-3" />
            Saved
        </span>
    );
}

function MaskedInput({
    id,
    value,
    onChange,
}: {
    id: string;
    value: string;
    onChange: (val: string) => void;
}) {
    const [revealed, setRevealed] = useState(false);

    return (
        <div className="relative">
            <Input
                id={id}
                type={revealed ? 'text' : 'password'}
                className="w-full pr-8 font-mono text-sm"
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
            <button
                type="button"
                tabIndex={-1}
                onClick={() => setRevealed(!revealed)}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
            >
                {revealed ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
        </div>
    );
}

function computeActiveKeys(creds: Record<string, string>): { key: string; secret: string } {
    if (creds.ALPACA_PAPER_TRADING === 'true') {
        return { key: creds.PAPER_ALPACA_KEY_ID ?? '', secret: creds.PAPER_ALPACA_SECRET_KEY ?? '' };
    }
    return { key: creds.PROD_ALPACA_KEY_ID ?? '', secret: creds.PROD_ALPACA_SECRET_KEY ?? '' };
}

function CredentialsForm({ initial, isPaperTrading: _pt }: { initial: Record<string, string>; isPaperTrading: boolean }) {
    const form = useForm(initial);
    const activeKeys = computeActiveKeys(form.data);

    function save(e: React.FormEvent) {
        e.preventDefault();
        form.setData({ ...form.data, ALPACA_API_KEY: activeKeys.key, ALPACA_API_SECRET: activeKeys.secret });
        form.patch(update().url, { preserveScroll: true });
    }

    function setVal(k: string, v: string) { form.setData(k as any, v); }

    return (
        <form onSubmit={save} className="space-y-6 max-w-2xl">
            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <HeadingSmall title="API Credentials" description="Manage API keys and secrets. Values are masked by default. Click the eye icon to reveal." />
                <div className="mt-6 space-y-6">
                    <div>
                        <h3 className="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">Alpaca Production</h3>
                        <div className="space-y-3">
                            <div><Label htmlFor="PROD_ALPACA_KEY_ID">Key ID</Label><Input id="PROD_ALPACA_KEY_ID" type="text" className="w-full font-mono text-sm" value={form.data.PROD_ALPACA_KEY_ID ?? ''} onChange={(e) => setVal('PROD_ALPACA_KEY_ID', e.target.value)} /></div>
                            <div><Label htmlFor="PROD_ALPACA_SECRET_KEY">Secret Key</Label><MaskedInput id="PROD_ALPACA_SECRET_KEY" value={form.data.PROD_ALPACA_SECRET_KEY ?? ''} onChange={(v) => setVal('PROD_ALPACA_SECRET_KEY', v)} /></div>
                        </div>
                    </div>
                    <div className="border-t pt-6">
                        <h3 className="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">Alpaca Paper</h3>
                        <div className="space-y-3">
                            <div><Label htmlFor="PAPER_ALPACA_KEY_ID">Key ID</Label><Input id="PAPER_ALPACA_KEY_ID" type="text" className="w-full font-mono text-sm" value={form.data.PAPER_ALPACA_KEY_ID ?? ''} onChange={(e) => setVal('PAPER_ALPACA_KEY_ID', e.target.value)} /></div>
                            <div><Label htmlFor="PAPER_ALPACA_SECRET_KEY">Secret Key</Label><MaskedInput id="PAPER_ALPACA_SECRET_KEY" value={form.data.PAPER_ALPACA_SECRET_KEY ?? ''} onChange={(v) => setVal('PAPER_ALPACA_SECRET_KEY', v)} /></div>
                        </div>
                    </div>
                    <div className="border-t pt-6">
                        <h3 className="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">
                            Alpaca Active Trading
                            <Badge variant="outline" className="ml-2 text-xs">Auto-populated from {form.data.ALPACA_PAPER_TRADING === 'true' ? 'Paper' : 'Production'} keys</Badge>
                        </h3>
                        <div className="space-y-3">
                            <div><Label htmlFor="ALPACA_API_KEY">API Key</Label><Input id="ALPACA_API_KEY" type="text" className="w-full font-mono text-sm bg-gray-50 dark:bg-gray-800" value={activeKeys.key} readOnly /></div>
                            <div><Label htmlFor="ALPACA_API_SECRET">API Secret</Label><Input id="ALPACA_API_SECRET" type="password" className="w-full font-mono text-sm bg-gray-50 dark:bg-gray-800" value={activeKeys.secret} readOnly /></div>
                            <div className="flex items-center gap-2 pt-2">
                                <Label htmlFor="ALPACA_PAPER_TRADING" className="font-medium">Paper Trading Mode</Label>
                                <Badge variant={form.data.ALPACA_PAPER_TRADING === 'true' ? 'secondary' : 'default'}>{form.data.ALPACA_PAPER_TRADING === 'true' ? 'Paper' : 'Live'}</Badge>
                                <button type="button" id="ALPACA_PAPER_TRADING" role="switch"
                                    aria-checked={form.data.ALPACA_PAPER_TRADING === 'true'}
                                    onClick={() => setVal('ALPACA_PAPER_TRADING', form.data.ALPACA_PAPER_TRADING === 'true' ? 'false' : 'true')}
                                    className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors ${form.data.ALPACA_PAPER_TRADING === 'true' ? 'bg-yellow-500 dark:bg-yellow-600' : 'bg-green-500 dark:bg-green-600'}`}>
                                    <span className={`pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-lg ring-0 transition-transform ${form.data.ALPACA_PAPER_TRADING === 'true' ? 'translate-x-5' : 'translate-x-0'}`} />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div className="border-t pt-6">
                        <h3 className="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">Mail</h3>
                        <div className="space-y-3">
                            <div><Label htmlFor="MAIL_USERNAME">Username</Label><Input id="MAIL_USERNAME" type="text" className="w-full font-mono text-sm" value={form.data.MAIL_USERNAME ?? ''} onChange={(e) => setVal('MAIL_USERNAME', e.target.value)} /></div>
                            <div><Label htmlFor="MAIL_PASSWORD">Password</Label><MaskedInput id="MAIL_PASSWORD" value={form.data.MAIL_PASSWORD ?? ''} onChange={(v) => setVal('MAIL_PASSWORD', v)} /></div>
                        </div>
                    </div>
                    <div className="border-t pt-6">
                        <h3 className="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">OpenAI</h3>
                        <div><Label htmlFor="OPENAI_API_KEY">API Key</Label><MaskedInput id="OPENAI_API_KEY" value={form.data.OPENAI_API_KEY ?? ''} onChange={(v) => setVal('OPENAI_API_KEY', v)} /></div>
                    </div>
                </div>
            </div>
            <div className="flex items-center justify-end gap-4">
                <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving...' : 'Save Credentials'}</Button>
                <SavedBadge show={form.recentlySuccessful} />
            </div>
        </form>
    );
}

const SCORER_PIPELINES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 'x', 'external', 'manual'];

function ScorerScriptsForm({ initial, displayNames }: { initial: Record<string, string>; displayNames: Record<string, string> }) {
    const form = useForm({ scorer_scripts: initial });

    function save(e: React.FormEvent) {
        e.preventDefault();
        form.patch(update().url, { preserveScroll: true });
    }

    function setVal(pipeline: string, val: string) {
        form.setData('scorer_scripts', { ...form.data.scorer_scripts, [pipeline]: val });
    }

    return (
        <form onSubmit={save} className="space-y-6 max-w-3xl">
            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <HeadingSmall
                    title="Pipeline Scorer Scripts"
                    description="Python scorer script path per pipeline. Changes also update the .env file."
                />
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="pb-2 pr-4 font-medium">Pipeline</th>
                                <th className="pb-2 pr-4 font-medium">Name</th>
                                <th className="pb-2 font-medium">Scorer Script</th>
                            </tr>
                        </thead>
                        <tbody>
                            {SCORER_PIPELINES.map((p) => (
                                <tr key={p} className="border-b last:border-0">
                                    <td className="py-2 pr-4 font-mono">{p.toUpperCase()}</td>
                                    <td className="py-2 pr-4 text-muted-foreground">{displayNames[p] ?? p.toUpperCase()}</td>
                                    <td className="py-2">
                                        <Input
                                            type="text"
                                            className="w-full font-mono text-xs"
                                            value={form.data.scorer_scripts[p] ?? ''}
                                            onChange={(e) => setVal(p, e.target.value)}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <div className="flex items-center justify-end gap-4">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving...' : 'Save Scorer Scripts'}
                </Button>
                <SavedBadge show={form.recentlySuccessful} />
            </div>
        </form>
    );
}

function ModelPathsForm({ initial, displayNames }: { initial: Record<string, string>; displayNames: Record<string, string> }) {
    const form = useForm({ model_paths: initial });

    function save(e: React.FormEvent) {
        e.preventDefault();
        form.patch(update().url, { preserveScroll: true });
    }

    function setVal(pipeline: string, val: string) {
        form.setData('model_paths', { ...form.data.model_paths, [pipeline]: val });
    }

    return (
        <form onSubmit={save} className="space-y-6 max-w-3xl">
            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <HeadingSmall
                    title="Pipeline ML Model Paths"
                    description="Path to the trained .joblib model file per pipeline. Changes also update the .env file."
                />
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="pb-2 pr-4 font-medium">Pipeline</th>
                                <th className="pb-2 pr-4 font-medium">Name</th>
                                <th className="pb-2 font-medium">Model Path</th>
                            </tr>
                        </thead>
                        <tbody>
                            {SCORER_PIPELINES.map((p) => (
                                <tr key={p} className="border-b last:border-0">
                                    <td className="py-2 pr-4 font-mono">{p.toUpperCase()}</td>
                                    <td className="py-2 pr-4 text-muted-foreground">{displayNames[p] ?? p.toUpperCase()}</td>
                                    <td className="py-2">
                                        <Input
                                            type="text"
                                            className="min-w-[500px] w-full font-mono text-xs"
                                            value={form.data.model_paths[p] ?? ''}
                                            onChange={(e) => setVal(p, e.target.value)}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <div className="flex items-center justify-end gap-4">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving...' : 'Save Model Paths'}
                </Button>
                <SavedBadge show={form.recentlySuccessful} />
            </div>
        </form>
    );
}

function OtherForm({ initial, newsLink }: { initial: boolean; newsLink: string }) {
    const form = useForm({ three_white_soldiers_scan_enabled: initial, news_link: newsLink });

    function save(e: React.FormEvent) {
        e.preventDefault();
        form.patch(updateOther().url, { preserveScroll: true });
    }

    return (
        <form onSubmit={save} className="space-y-6 max-w-2xl">
            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <HeadingSmall
                    title="Scanner Settings"
                    description="Controls for automated pattern scanning commands."
                />
                <div className="mt-6 space-y-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <Label className="font-medium">Three White Soldiers Scanner</Label>
                            <p className="text-sm text-muted-foreground">
                                Runs <code className="text-xs bg-muted px-1 py-0.5 rounded">scan:three-white-soldiers-live</code> every minute to detect CDL3WHITESOLDIERS patterns.
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={form.data.three_white_soldiers_scan_enabled}
                            onClick={() => {
                                form.setData('three_white_soldiers_scan_enabled', !form.data.three_white_soldiers_scan_enabled);
                                // Auto-save on toggle
                                setTimeout(() => form.patch(updateOther().url, { preserveScroll: true }), 50);
                            }}
                            className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors ${form.data.three_white_soldiers_scan_enabled ? 'bg-green-500 dark:bg-green-600' : 'bg-gray-300 dark:bg-gray-600'}`}
                        >
                            <span className={`pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-lg ring-0 transition-transform ${form.data.three_white_soldiers_scan_enabled ? 'translate-x-5' : 'translate-x-0'}`} />
                        </button>
                    </div>
                </div>
            </div>
            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <HeadingSmall
                    title="News Link"
                    description="URL template for stock news pages. Use &lt;SYMBOL&gt; as a placeholder for the ticker symbol."
                />
                <div className="mt-4">
                    <Label htmlFor="news_link">News URL</Label>
                    <Input
                        id="news_link"
                        type="text"
                        className="mt-1 w-full font-mono text-sm"
                        value={form.data.news_link}
                        onChange={(e) => form.setData('news_link', e.target.value)}
                    />
                </div>
            </div>
            <div className="flex items-center justify-end gap-4">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving...' : 'Save Settings'}
                </Button>
                <SavedBadge show={form.recentlySuccessful} />
            </div>
        </form>
    );
}

export default function TradingSettings2({ credentials, scorerScripts, modelPaths, pipelineDisplayNames, isPaperTrading, threeWhiteSoldiersScanEnabled, newsLink }: Props) {
    return (
        <>
            <Head title="Trade Settings 2" />
            <AppLayout breadcrumbs={breadcrumbs}>
                <div className="flex flex-col gap-6 p-6">
                    <HeadingSmall title="Trade Settings 2" description="Additional trading configuration. All changes are saved to the DB and cached in Redis immediately." />
                    {isPaperTrading && (
                        <div className="flex items-center gap-2 rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-800 dark:bg-yellow-950/40">
                            <ShieldAlert className="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                            <span className="text-sm font-medium text-yellow-800 dark:text-yellow-200">Paper Trading Mode — no real orders will be placed.</span>
                        </div>
                    )}
                    <Tabs defaultValue="credentials" className="w-full">
                        <TabsList className="flex-wrap">
                            <TabsTrigger value="credentials">Credentials</TabsTrigger>
                            <TabsTrigger value="scorer-scripts">Scorer Scripts</TabsTrigger>
                            <TabsTrigger value="model-paths">Model Paths</TabsTrigger>
                            <TabsTrigger value="other">Other</TabsTrigger>
                        </TabsList>
                        <TabsContent value="credentials">
                            <CredentialsForm initial={credentials} isPaperTrading={isPaperTrading} />
                        </TabsContent>
                        <TabsContent value="scorer-scripts">
                            <ScorerScriptsForm initial={scorerScripts} displayNames={pipelineDisplayNames} />
                        </TabsContent>
                        <TabsContent value="model-paths">
                            <ModelPathsForm initial={modelPaths} displayNames={pipelineDisplayNames} />
                        </TabsContent>
                        <TabsContent value="other">
                            <OtherForm initial={threeWhiteSoldiersScanEnabled} newsLink={newsLink} />
                        </TabsContent>
                    </Tabs>
                </div>
            </AppLayout>
        </>
    );
}
