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
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { MessageSquare, Calendar, BarChart } from 'lucide-react';
import { useState, useEffect } from 'react';

interface Asset {
    id: number;
    symbol: string;
    common_name?: string;
    sector?: string;
}

interface Sentiment {
    id: number;
    symbol: string;
    sentiment_text: string;
    sentiment_type: 'positive' | 'negative' | 'neutral';
    confidence_score?: number;
    sentiment_date: string;
    asset?: Asset;
}

interface SentimentsProps {
    sentiments: Sentiment[];
    selectedDate: string;
    availableDates: string[];
}

export default function Sentiments({ sentiments, selectedDate, availableDates }: SentimentsProps) {
    const [searchTerm, setSearchTerm] = useState('');

    const handleDateChange = (date: string) => {
        router.get('/sentiments', { date }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const filteredSentiments = sentiments.filter((sentiment) => {
        const matchesSearch = sentiment.symbol.toLowerCase().includes(searchTerm.toLowerCase()) ||
                            sentiment.sentiment_text.toLowerCase().includes(searchTerm.toLowerCase()) ||
                            sentiment.asset?.common_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
                            false;
        
        return matchesSearch;
    });

    const formatDate = (dateString: string) => {
        // Parse the date string as local date to avoid timezone conversion issues
        const [year, month, day] = dateString.split('-').map(Number);
        return new Date(year, month - 1, day).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Sentiments', href: '/sentiments' }]}>
            <Head title="Market Sentiments" />
            
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <Heading
                    title="Market Sentiments"
                    description="AI-powered sentiment analysis for major stocks"
                />

                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div className="flex flex-col gap-4 md:flex-row md:items-end md:gap-6">
                                <div className="flex-1">
                                    <Label htmlFor="date" className="flex items-center gap-2">
                                        <Calendar className="h-4 w-4" />
                                        Date
                                    </Label>
                                    <select
                                        id="date"
                                        value={selectedDate}
                                        onChange={(e) => handleDateChange(e.target.value)}
                                        className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm md:w-48"
                                    >
                                        {availableDates.map((date) => (
                                            <option key={date} value={date}>
                                                {formatDate(date)}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="flex-1">
                                    <Label htmlFor="search">Search</Label>
                                    <Input
                                        id="search"
                                        placeholder="Search symbols or sentiment text..."
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        className="mt-1 w-full md:w-80"
                                    />
                                </div>
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Showing {filteredSentiments.length} of {sentiments.length} sentiments
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* CSV Export */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex items-center justify-between">
                            <div>
                                <Label className="flex items-center gap-2 font-medium">
                                    <BarChart className="h-4 w-4" />
                                    Symbol List ({filteredSentiments.length} symbols)
                                </Label>
                                <p className="text-sm text-muted-foreground mt-1">
                                    CSV format of all symbols currently displayed in the table
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    const symbols = filteredSentiments.map(sentiment => sentiment.symbol).join(', ');
                                    navigator.clipboard.writeText(symbols);
                                }}
                                className="gap-2"
                            >
                                Copy CSV
                            </Button>
                        </div>
                        <div className="mt-3 p-3 bg-muted rounded-md">
                            <code className="text-sm break-all">
                                {filteredSentiments.map(sentiment => sentiment.symbol).join(', ')}
                            </code>
                        </div>
                    </CardContent>
                </Card>

                {/* Sentiments List */}
                {filteredSentiments.length === 0 ? (
                    <Card className="text-center py-12">
                        <CardContent className="flex flex-col items-center justify-center gap-4">
                            <div className="text-5xl">📊</div>
                            <div>
                                <CardTitle>No sentiments found</CardTitle>
                                <CardDescription className="mt-1">
                                    Try adjusting your search criteria or select a different date.
                                </CardDescription>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-[100px]">Symbol</TableHead>
                                    <TableHead>Company</TableHead>
                                    <TableHead>Sentiment</TableHead>
                                    <TableHead className="w-[100px]">Sector</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredSentiments.map((sentiment) => (
                                    <TableRow key={sentiment.id}>
                                        <TableCell className="font-bold">
                                            {sentiment.asset?.id ? (
                                                <Link 
                                                    href={`/market-data/assets/${sentiment.asset.id}`}
                                                    className="text-blue-600 hover:text-blue-800 hover:underline transition-colors"
                                                >
                                                    {sentiment.symbol}
                                                </Link>
                                            ) : (
                                                sentiment.symbol
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {sentiment.asset?.common_name || '—'}
                                        </TableCell>
                                        <TableCell className="max-w-[400px]">
                                            <p className="text-sm leading-relaxed line-clamp-2">
                                                {sentiment.sentiment_text}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            {sentiment.asset?.sector ? (
                                                <Badge variant="secondary" className="text-xs">
                                                    {sentiment.asset.sector}
                                                </Badge>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}