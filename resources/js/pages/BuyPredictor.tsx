import Heading from '@/components/heading';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Form, Link } from '@inertiajs/react';
import { Target, TrendingUp, Calendar, Clock, AlertCircle, Search, RefreshCw } from 'lucide-react';
import { show as showAsset } from '@/routes/asset-info';
import { useState } from 'react';

interface BuyPredictorResult {
    symbol: string;
    asset_id: number | null;
    score: number;
    last_price: number;
    range_pct: number;
    pullback_from_high: number;
    rising_momentum: boolean;
    momentum_strength?: number;
    momentum_consistency?: number;
    trend_slope?: number;
    vwap: number;
    ma5_5m: number | null;
    ma20_5m: number | null;
    ma5_1m: number | null;
    ma20_1m: number | null;
    session_high: number;
    session_low: number;
    first_price: number;
    bars_count_5m: number;
    bars_count_1m: number;
    // Predictive pattern fields
    morning_gain_pct?: number;
    morning_range_pct?: number;
    pullback_from_morning_high?: number;
    volume_acceleration?: number;
    consolidation_quality?: number;
    breakout_angle?: number;
    predictive_score?: number;
    // Volume analysis fields
    volume_breakout_strength?: number;
    volume_trend_score?: number;
    price_volume_correlation?: number;
    breakout_volume_ratio?: number;
    // Sector analysis fields
    sector_name?: string;
    sector_score?: number;
    exchange_type?: string;
    // Stop-loss verification fields
    stop_loss_price?: number;
    risk_reward_ratio?: number;
    distance_from_low_pct?: number;
    stop_loss_score?: number;
}

interface BuyPredictorMeta {
    as_of: string;
    lookback_minutes: number;
    asset_type: string;
    min_score: number;
    total_symbols: number;
    data_window: {
        start: string;
        end: string;
    };
    message?: string;
}

interface BuyPredictorData {
    results: BuyPredictorResult[];
    meta: BuyPredictorMeta;
}

interface BuyPredictorProps {
    title: string;
    description: string;
    results: BuyPredictorData | null;
    params: {
        as_of: string | null;
        lookback_minutes: number;
        asset_type: string;
        min_score: number;
    };
}

export default function BuyPredictor({
    title,
    description,
    results,
    params
}: BuyPredictorProps) {
    const [searchTerm, setSearchTerm] = useState('');
    const [formData, setFormData] = useState({
        as_of: params.as_of || '',
        lookback_minutes: params.lookback_minutes || 90,
        asset_type: params.asset_type || 'stock',
        min_score: params.min_score || 5,
    });

    const filteredResults = results?.results.filter(result =>
        result.symbol.toLowerCase().includes(searchTerm.toLowerCase())
    ) || [];

    const getScoreColor = (score: number) => {
        if (score >= 8) return 'bg-green-500';
        if (score >= 6) return 'bg-blue-500';
        if (score >= 4) return 'bg-yellow-500';
        return 'bg-gray-500';
    };

    const formatPercent = (value: number) => {
        return `${value > 0 ? '+' : ''}${value.toFixed(2)}%`;
    };

    const formatPrice = (value: number | null) => {
        if (value === null) return 'N/A';
        return `$${value.toFixed(4)}`;
    };

    const getPullbackColor = (pullback: number) => {
        if (pullback >= 1.0 && pullback <= 3.0) return 'text-green-600'; // Optimal entry zone
        if (pullback >= 0.5 && pullback < 1.0) return 'text-blue-600';   // Good entry
        if (pullback >= 3.0 && pullback <= 5.0) return 'text-yellow-600'; // Acceptable
        if (pullback < 0.5) return 'text-red-600';                        // Too close to high
        return 'text-gray-600';                                           // Too much pullback
    };

    const calculateUpsideToHigh = (lastPrice: number, sessionHigh: number) => {
        return ((sessionHigh / lastPrice) - 1) * 100;
    };

    const formatDateTime = (dateTime: string) => {
        return new Date(dateTime + ' EST').toLocaleString();
    };

    const currentDateTime = new Date().toISOString().slice(0, 16);

    return (
        <AppLayout>
            <Head title={title} />
            
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex items-center gap-3">
                    <Target className="h-8 w-8 text-blue-600" />
                    <Heading
                        title={title}
                        description={description}
                    />
                </div>

                {/* Analysis Form */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Search className="h-5 w-5" />
                            Analysis Parameters
                        </CardTitle>
                        <CardDescription>
                            Configure the buy predictor analysis parameters
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form method="get">
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div>
                                    <Label htmlFor="as_of" className="flex items-center gap-1">
                                        <Calendar className="h-4 w-4" />
                                        As Of Time (EST)
                                    </Label>
                                    <Input
                                        type="datetime-local"
                                        id="as_of"
                                        name="as_of"
                                        value={formData.as_of}
                                        max={currentDateTime}
                                        onChange={(e) => setFormData(prev => ({ ...prev, as_of: e.target.value }))}
                                        placeholder="Leave empty for current time"
                                        className="mt-1"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="lookback_minutes" className="flex items-center gap-1">
                                        <Clock className="h-4 w-4" />
                                        Lookback Minutes
                                    </Label>
                                    <Input
                                        type="number"
                                        id="lookback_minutes"
                                        name="lookback_minutes"
                                        min="5"
                                        max="480"
                                        value={formData.lookback_minutes}
                                        onChange={(e) => setFormData(prev => ({ ...prev, lookback_minutes: parseInt(e.target.value) || 90 }))}
                                        className="mt-1"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="asset_type">Asset Type</Label>
                                    <Select
                                        name="asset_type"
                                        value={formData.asset_type}
                                        onValueChange={(value) => setFormData(prev => ({ ...prev, asset_type: value }))}
                                    >
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="stock">Stock</SelectItem>
</SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label htmlFor="min_score">Minimum Score</Label>
                                    <Input
                                        type="number"
                                        id="min_score"
                                        name="min_score"
                                        min="1"
                                        max="20"
                                        value={formData.min_score}
                                        onChange={(e) => setFormData(prev => ({ ...prev, min_score: parseInt(e.target.value) || 5 }))}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div className="flex justify-end mt-6">
                                <Button type="submit" className="flex items-center gap-2">
                                    <RefreshCw className="h-4 w-4" />
                                    Run Analysis
                                </Button>
                            </div>
                        </Form>
                    </CardContent>
                </Card>

                {/* Results */}
                {results && (
                    <Card>
                        <CardHeader>
                            <div className="flex justify-between items-start">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        <TrendingUp className="h-5 w-5" />
                                        Buy Predictor Results
                                    </CardTitle>
                                    <CardDescription>
                                        Analysis as of {formatDateTime(results.meta.as_of)} | 
                                        {results.meta.lookback_minutes} minute lookback | 
                                        {results.meta.asset_type} assets | 
                                        minimum score {results.meta.min_score}
                                    </CardDescription>
                                </div>
                                <Badge variant="outline">
                                    {results.meta.total_symbols} symbols found
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {results.meta.message && (
                                <Alert className="mb-6">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>
                                        {results.meta.message}
                                    </AlertDescription>
                                </Alert>
                            )}

                            {results.results.length > 0 && (
                                <>
                                    <div className="mb-4">
                                        <Input
                                            type="text"
                                            placeholder="Search symbols..."
                                            value={searchTerm}
                                            onChange={(e) => setSearchTerm(e.target.value)}
                                            className="max-w-sm"
                                        />
                                    </div>

                                    <div className="rounded-md border">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Symbol</TableHead>
                                                    <TableHead>Score</TableHead>
                                                    <TableHead>Rising</TableHead>
                                                    <TableHead>R/R Ratio</TableHead>
                                                    <TableHead>Sector</TableHead>
                                                    <TableHead>Vol Strength</TableHead>
                                                    <TableHead>Stop Loss</TableHead>
                                                    <TableHead>Last Price</TableHead>
                                                    <TableHead>Range %</TableHead>
                                                    <TableHead>Pullback %</TableHead>
                                                    <TableHead>Session High</TableHead>
                                                    <TableHead>Session Low</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {filteredResults.map((result, index) => (
                                                    <TableRow key={index}>
                                                        <TableCell className="font-mono font-semibold">
                                                            {result.asset_id ? (
                                                                <Link
                                                                    href={showAsset.url(result.asset_id)}
                                                                    className="text-blue-600 hover:text-blue-800 hover:underline"
                                                                >
                                                                    {result.symbol}
                                                                </Link>
                                                            ) : (
                                                                <span className="text-gray-500">{result.symbol}</span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge className={getScoreColor(result.score)}>
                                                                {result.score}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell>
                                                            {result.rising_momentum ? (
                                                                <Badge className="bg-green-100 text-green-800 border-green-300">
                                                                    📈 Rising
                                                                </Badge>
                                                            ) : (
                                                                <Badge className="bg-gray-100 text-gray-600 border-gray-300">
                                                                    ⚪ Flat
                                                                </Badge>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="font-mono">
                                                            {result.risk_reward_ratio !== undefined ? (
                                                                <span className={result.risk_reward_ratio >= 3.0 ? 'text-green-600 font-semibold' : result.risk_reward_ratio >= 2.0 ? 'text-amber-600' : 'text-red-600'}>
                                                                    {result.risk_reward_ratio.toFixed(1)}:1
                                                                </span>
                                                            ) : '-'}
                                                        </TableCell>
                                                        <TableCell className="font-mono text-sm">
                                                            {result.sector_name ? (
                                                                <Badge variant="outline" className="text-xs">
                                                                    {result.sector_name}
                                                                </Badge>
                                                            ) : '-'}
                                                        </TableCell>
                                                        <TableCell className="font-mono">
                                                            {result.volume_breakout_strength !== undefined ? (
                                                                <span className={result.volume_breakout_strength >= 20 ? 'text-green-600 font-semibold' : result.volume_breakout_strength >= 10 ? 'text-amber-600' : 'text-gray-600'}>
                                                                    {result.volume_breakout_strength}
                                                                </span>
                                                            ) : '-'}
                                                        </TableCell>
                                                        <TableCell className="font-mono text-sm">
                                                            {result.stop_loss_score !== undefined ? (
                                                                <span className={result.stop_loss_score >= 40 ? 'text-green-600 font-semibold' : result.stop_loss_score >= 25 ? 'text-amber-600' : 'text-red-600'}>
                                                                    {result.stop_loss_score}
                                                                </span>
                                                            ) : '-'}
                                                        </TableCell>
                                                        <TableCell className="font-mono">
                                                            {formatPrice(result.last_price)}
                                                        </TableCell>
                                                        <TableCell className={`font-mono ${result.range_pct >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                                            {formatPercent(result.range_pct)}
                                                        </TableCell>
                                                        <TableCell className={`font-mono ${getPullbackColor(result.pullback_from_high)}`}>
                                                            {formatPercent(result.pullback_from_high)}
                                                        </TableCell>
                                                        <TableCell className="font-mono">
                                                            {formatPrice(result.session_high)}
                                                        </TableCell>
                                                        <TableCell className="font-mono">
                                                            {formatPrice(result.session_low)}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
