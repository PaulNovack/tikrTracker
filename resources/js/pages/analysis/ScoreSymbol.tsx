import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { useState } from 'react';
import { TrendingUp, TrendingDown, Target, Clock, DollarSign } from 'lucide-react';

interface ScoreResult {
    success: boolean;
    symbol: string;
    ml_win_prob: number;
    is_buy: boolean;
    threshold: number;
    alert_id: number;
    entry_price: number;
    stop_price: number;
    score: number;
    vol_ratio: number;
    atr_pct: number;
    rsi_14_1m: number;
    scored_at: string;
    model_version: string;
    entry_ts_est: string;
    data_age_seconds?: number;
    message?: string;
}

export default function ScoreSymbol() {
    const [symbol, setSymbol] = useState('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<ScoreResult | null>(null);
    const [error, setError] = useState<string | null>(null);

    const handleScore = async () => {
        if (!symbol.trim()) {
            setError('Please enter a symbol');
            return;
        }

        setLoading(true);
        setError(null);
        setResult(null);

        try {
            const response = await fetch('/analysis/score-symbol', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({ symbol: symbol.trim().toUpperCase() }),
            });

            const data = await response.json();

            if (data.success) {
                setResult(data);
                setError(null);
            } else {
                setError(data.message || 'Failed to score symbol');
                setResult(null);
            }
        } catch (err) {
            setError('An error occurred while scoring the symbol');
            setResult(null);
        } finally {
            setLoading(false);
        }
    };

    const handleKeyPress = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter') {
            handleScore();
        }
    };

    return (
        <AppLayout>
            <Head title="Score Symbol - Analysis" />
            
            <div className="container mx-auto py-6 space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Score Symbol</CardTitle>
                        <CardDescription>
                            Get ML-powered buy/sell signals for any stock symbol
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="flex gap-4 items-end">
                            <div className="flex-1 max-w-sm">
                                <Label htmlFor="symbol">Stock Symbol</Label>
                                <Input
                                    id="symbol"
                                    type="text"
                                    placeholder="e.g., AAPL, TSLA"
                                    value={symbol}
                                    onChange={(e) => setSymbol(e.target.value.toUpperCase())}
                                    onKeyPress={handleKeyPress}
                                    disabled={loading}
                                    className="uppercase"
                                />
                            </div>
                            <Button 
                                onClick={handleScore} 
                                disabled={loading || !symbol.trim()}
                                className="gap-2"
                            >
                                <Target className="h-4 w-4" />
                                {loading ? 'Scoring...' : 'Score'}
                            </Button>
                        </div>

                        {error && (
                            <div className="p-4 bg-destructive/10 border border-destructive/20 rounded-lg">
                                <p className="text-sm text-destructive">{error}</p>
                            </div>
                        )}

                        {result && (
                            <div className="space-y-4">
                                <div className="flex items-center gap-4">
                                    <h3 className="text-2xl font-bold">{result.symbol}</h3>
                                    <Badge
                                        variant={result.is_buy ? 'default' : 'destructive'}
                                        className="text-lg px-4 py-1"
                                    >
                                        {result.is_buy ? (
                                            <span className="flex items-center gap-2">
                                                <TrendingUp className="h-5 w-5" />
                                                BUY
                                            </span>
                                        ) : (
                                            <span className="flex items-center gap-2">
                                                <TrendingDown className="h-5 w-5" />
                                                NO BUY
                                            </span>
                                        )}
                                    </Badge>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardDescription>ML Win Probability</CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-3xl font-bold">
                                                {(result.ml_win_prob * 100).toFixed(2)}%
                                            </div>
                                            <p className="text-xs text-muted-foreground mt-1">
                                                Threshold: {(result.threshold * 100).toFixed(0)}%
                                            </p>
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardDescription>Entry Price</CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-3xl font-bold">
                                                ${result.entry_price.toFixed(2)}
                                            </div>
                                            <p className="text-xs text-muted-foreground mt-1">
                                                Stop: ${result.stop_price.toFixed(2)}
                                            </p>
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardDescription>Signal Score</CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-3xl font-bold">
                                                {result.score.toFixed(2)}
                                            </div>
                                            <p className="text-xs text-muted-foreground mt-1">
                                                Vol Ratio: {result.vol_ratio.toFixed(2)}x
                                            </p>
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardDescription>ATR %</CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-3xl font-bold">
                                                {result.atr_pct.toFixed(2)}%
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardDescription>RSI (1m)</CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-3xl font-bold">
                                                {result.rsi_14_1m.toFixed(1)}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardDescription>Model Version</CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-lg font-mono">
                                                {result.model_version}
                                            </div>
                                            <p className="text-xs text-muted-foreground mt-1">
                                                Alert #{result.alert_id}
                                            </p>
                                        </CardContent>
                                    </Card>
                                </div>

                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Clock className="h-4 w-4" />
                                    <span>Data from: {new Date(result.entry_ts_est).toLocaleString()}</span>
                                    {result.data_age_seconds !== undefined && (
                                        <Badge variant="outline" className="ml-2">
                                            {result.data_age_seconds < 60 
                                                ? `${result.data_age_seconds}s old`
                                                : result.data_age_seconds < 3600 
                                                ? `${Math.floor(result.data_age_seconds / 60)}m old`
                                                : `${Math.floor(result.data_age_seconds / 3600)}h old`}
                                        </Badge>
                                    )}
                                </div>

                                <div className="p-4 bg-muted/50 rounded-lg">
                                    <h4 className="font-semibold mb-2">Understanding the Score</h4>
                                    <p className="text-sm text-muted-foreground">
                                        The ML Win Probability represents the likelihood of a profitable trade based on historical patterns 
                                        and current market data. A score above {(result.threshold * 100).toFixed(0)}% is considered a buy signal. 
                                        This score is generated in real-time using the latest available 1-minute and 5-minute price data.
                                    </p>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
