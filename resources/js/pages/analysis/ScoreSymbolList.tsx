import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { useState, useEffect, useCallback } from 'react';
import { TrendingUp, TrendingDown, RefreshCw, Clock, DollarSign, Target } from 'lucide-react';

interface SymbolResult {
    success: boolean;
    symbol: string;
    ml_win_prob?: number;
    is_buy?: boolean;
    threshold?: number;
    entry_price?: number;
    stop_price?: number;
    atr_pct?: number;
    entry_ts_est?: string;
    data_age_seconds?: number;
    message?: string;
}

interface ScoreResponse {
    success: boolean;
    batch_id?: string;
    total?: number;
    message?: string;
    bell_threshold?: number;
}

interface StatusResponse {
    success: boolean;
    batch_id: string;
    total: number;
    completed: number;
    is_complete: boolean;
    results: Record<string, SymbolResult>;
    scored_at: string;
}

export default function ScoreSymbolList() {
    const [symbolsText, setSymbolsText] = useState('');
    const [loading, setLoading] = useState(false);
    const [results, setResults] = useState<Record<string, SymbolResult>>({});
    const [scoredAt, setScoredAt] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [autoRefresh, setAutoRefresh] = useState(false);
    const [countdown, setCountdown] = useState(60);
    const [batchId, setBatchId] = useState<string | null>(null);
    const [progress, setProgress] = useState({ completed: 0, total: 0 });
    const [bellThreshold, setBellThreshold] = useState<number>(0.70);
    const [bellPlayed, setBellPlayed] = useState<Set<string>>(new Set());
    const [pollStartTime, setPollStartTime] = useState<number | null>(null);
    const [lastProgressUpdate, setLastProgressUpdate] = useState<number>(Date.now());

    const playBell = useCallback(() => {
        try {
            // Use Web Audio API to generate a simple bell tone
            const audioContext = new (window.AudioContext || (window as any).webkitAudioContext)();
            
            // Create oscillator for bell sound (mix of frequencies)
            const oscillator1 = audioContext.createOscillator();
            const oscillator2 = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator1.connect(gainNode);
            oscillator2.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            // Bell-like frequencies
            oscillator1.frequency.value = 800;
            oscillator2.frequency.value = 1200;
            
            // Envelope for natural bell decay
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
            
            oscillator1.start(audioContext.currentTime);
            oscillator2.start(audioContext.currentTime);
            oscillator1.stop(audioContext.currentTime + 0.5);
            oscillator2.stop(audioContext.currentTime + 0.5);
        } catch (error) {
            // Silently fail if bell sound can't play - don't interrupt polling
            console.warn('Bell sound failed to play:', error);
        }
    }, []);

    const pollStatus = useCallback(async (currentBatchId: string) => {
        try {
            const response = await fetch(`/analysis/score-symbol-list/status/${currentBatchId}`);
            const data: StatusResponse = await response.json();

            if (data.success) {
                // Check for new high-scoring symbols and play bell
                if (data.results) {
                    Object.entries(data.results).forEach(([symbol, result]) => {
                        if (result.success && 
                            result.ml_win_prob !== undefined && 
                            result.ml_win_prob >= bellThreshold && 
                            !bellPlayed.has(symbol)) {
                            playBell();
                            setBellPlayed(prev => new Set(prev).add(symbol));
                        }
                    });
                    
                    setResults(data.results);
                }
                
                setScoredAt(data.scored_at);
                
                // Update progress and track when it last changed
                const prevCompleted = progress.completed;
                setProgress({ completed: data.completed, total: data.total });
                if (data.completed > prevCompleted) {
                    setLastProgressUpdate(Date.now());
                }

                // Complete when all symbols are done or when marked complete
                if (data.is_complete || data.completed >= data.total) {
                    setLoading(false);
                    setBatchId(null);
                    setCountdown(60); // Reset countdown when batch completes
                    return true; // Complete
                }
            }
        } catch (err) {
            console.error('Polling error:', err);
        }
        return false; // Not complete
    }, [bellThreshold, bellPlayed, playBell]);

    // Poll for results when batch is active
    useEffect(() => {
        if (!batchId) return;

        const startTime = Date.now();
        setPollStartTime(startTime);
        setLastProgressUpdate(startTime);
        const MAX_POLL_TIME = 10 * 60 * 1000; // 10 minutes absolute timeout
        const STALL_TIMEOUT = 120 * 1000; // 120 seconds without progress (double from 60s)

        const interval = setInterval(async () => {
            const elapsed = Date.now() - startTime;
            const timeSinceProgress = Date.now() - lastProgressUpdate;
            
            // Absolute timeout after 5 minutes
            if (elapsed > MAX_POLL_TIME) {
                clearInterval(interval);
                setLoading(false);
                setBatchId(null);
                setError(`Scoring timeout after 5 minutes. Showing ${progress.completed} of ${progress.total} symbols.`);
                setCountdown(60);
                return;
            }

            // Stall timeout - no progress for 60 seconds
            if (timeSinceProgress > STALL_TIMEOUT && progress.completed > 0) {
                clearInterval(interval);
                setLoading(false);
                setBatchId(null);
                setError(`Scoring stalled. Completed ${progress.completed} of ${progress.total} symbols. Some jobs may have failed.`);
                setCountdown(60);
                return;
            }

            const isComplete = await pollStatus(batchId);
            if (isComplete) {
                clearInterval(interval);
            }
        }, 1000); // Poll every second

        return () => clearInterval(interval);
    }, [batchId, pollStatus, lastProgressUpdate, progress]);

    const scoreSymbols = useCallback(async (symbols?: string[]) => {
        const symbolsToScore = symbols || symbolsText
            .split(/[,\n\s]+/)
            .map(s => s.trim().toUpperCase())
            .filter(s => s.length > 0);

        if (symbolsToScore.length === 0) {
            setError('Please enter at least one symbol');
            return;
        }

        setLoading(true);
        setError(null);
        setResults({});
        setProgress({ completed: 0, total: symbolsToScore.length });
        setBellPlayed(new Set()); // Reset bell tracking for new batch

        try {
            const response = await fetch('/analysis/score-symbol-list', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({ symbols: symbolsToScore }),
            });

            const data: ScoreResponse = await response.json();

            if (data.success && data.batch_id) {
                setBatchId(data.batch_id);
                if (data.bell_threshold !== undefined) {
                    setBellThreshold(data.bell_threshold);
                }
                // Polling will start automatically via useEffect
            } else {
                setError(data.message || 'Failed to score symbols');
                setLoading(false);
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : 'An error occurred');
            setLoading(false);
        }
    }, [symbolsText]);

    const loadTopMovers = useCallback(async () => {
        try {
            const response = await fetch('/analysis/score-symbol-list/top-movers');
            const data = await response.json();

            if (data.success && data.symbols) {
                setSymbolsText(data.symbols.join(', '));
                setError(null);
            } else {
                setError(data.message || 'Failed to load top movers');
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Failed to load top movers');
        }
    }, []);

    // Auto-refresh countdown
    useEffect(() => {
        if (!autoRefresh || Object.keys(results).length === 0 || loading) {
            return;
        }

        const timer = setInterval(() => {
            setCountdown(prev => {
                if (prev <= 1) {
                    scoreSymbols(Object.keys(results));
                    return 60;
                }
                return prev - 1;
            });
        }, 1000);

        return () => clearInterval(timer);
    }, [autoRefresh, results, scoreSymbols, loading]);

    const sortedResults = Object.values(results || {}).sort((a, b) => {
        if (a.success && !b.success) return -1;
        if (!a.success && b.success) return 1;
        if (a.success && b.success) {
            return (b.ml_win_prob || 0) - (a.ml_win_prob || 0);
        }
        return 0;
    });

    return (
        <AppLayout>
            <Head title="Score Symbol List" />

            <div className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-bold">Score Symbol List</h1>
                    <p className="text-muted-foreground">
                        Score multiple symbols with ML predictions and auto-refresh
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Enter Symbols</CardTitle>
                        <CardDescription>
                            Enter symbols separated by commas, spaces, or new lines
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="symbols">Symbols</Label>
                            <Textarea
                                id="symbols"
                                placeholder="AAPL, TSLA, MSFT&#10;GOOGL&#10;AMZN"
                                value={symbolsText}
                                onChange={(e) => setSymbolsText(e.target.value)}
                                rows={4}
                                disabled={loading}
                            />
                        </div>

                        <div className="flex gap-4 items-center">
                            <Button
                                onClick={() => scoreSymbols()}
                                disabled={loading}
                            >
                                {loading ? `Scoring... (${progress.completed}/${progress.total})` : 'Score Symbols'}
                            </Button>

                            <Button
                                variant="outline"
                                onClick={loadTopMovers}
                                disabled={loading}
                            >
                                Load Top Movers
                            </Button>

                            {Object.keys(results).length > 0 && (
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant={autoRefresh ? 'default' : 'outline'}
                                        onClick={() => setAutoRefresh(!autoRefresh)}
                                        size="sm"
                                    >
                                        <RefreshCw className={`h-4 w-4 mr-2 ${autoRefresh ? 'animate-spin' : ''}`} />
                                        {autoRefresh ? `Auto (${countdown}s)` : 'Enable Auto-Refresh'}
                                    </Button>
                                </div>
                            )}
                        </div>

                        {error && (
                            <div className="text-red-500 text-sm">{error}</div>
                        )}

                        {scoredAt && (
                            <div className="flex items-center text-sm text-muted-foreground">
                                <Clock className="h-4 w-4 mr-2" />
                                Last scored: {new Date(scoredAt).toLocaleString()}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {sortedResults.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Results ({sortedResults.length} symbols)</CardTitle>
                            <CardDescription>
                                Sorted by ML win probability (highest first)
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                {sortedResults.map((result) => (
                                    <div
                                        key={result.symbol}
                                        className={`border rounded-lg p-4 ${
                                            result.success && result.is_buy
                                                ? 'border-green-500/50 bg-green-500/5'
                                                : 'border-border'
                                        }`}
                                    >
                                        <div className="flex items-start justify-between">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-3">
                                                    <h3 className="text-xl font-bold">{result.symbol}</h3>
                                                    {result.success ? (
                                                        <>
                                                            <Badge
                                                                variant={result.is_buy ? 'default' : 'secondary'}
                                                                className="gap-1"
                                                            >
                                                                {result.is_buy ? (
                                                                    <TrendingUp className="h-3 w-3" />
                                                                ) : (
                                                                    <TrendingDown className="h-3 w-3" />
                                                                )}
                                                                {result.is_buy ? 'BUY' : 'PASS'}
                                                            </Badge>
                                                            <Badge variant="outline">
                                                                {((result.ml_win_prob || 0) * 100).toFixed(1)}% Win Prob
                                                            </Badge>
                                                        </>
                                                    ) : (
                                                        <Badge variant="destructive">Error</Badge>
                                                    )}
                                                </div>

                                                {result.success && (
                                                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mt-3">
                                                        <div>
                                                            <div className="text-xs text-muted-foreground">Entry Price</div>
                                                            <div className="font-medium flex items-center gap-1">
                                                                <DollarSign className="h-3 w-3" />
                                                                {result.entry_price?.toFixed(2)}
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <div className="text-xs text-muted-foreground">Stop Price</div>
                                                            <div className="font-medium flex items-center gap-1">
                                                                <Target className="h-3 w-3" />
                                                                {result.stop_price?.toFixed(2)}
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <div className="text-xs text-muted-foreground">ATR %</div>
                                                            <div className="font-medium">{result.atr_pct?.toFixed(2)}%</div>
                                                        </div>
                                                        <div>
                                                            <div className="text-xs text-muted-foreground">Data Age</div>
                                                            <div className="font-medium">{result.data_age_seconds}s</div>
                                                        </div>
                                                    </div>
                                                )}

                                                {!result.success && result.message && (
                                                    <div className="text-sm text-destructive mt-2">{result.message}</div>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
