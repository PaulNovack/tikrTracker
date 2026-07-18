import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Head } from '@inertiajs/react';
import { CheckCircle } from 'lucide-react';

const criteria = [
    'Price is above VWAP',
    '1-minute EMA9 > EMA21',
    '5-minute trend is up or reclaiming',
    'Volume ratio >= 2.0',
    'Spread <= 0.08%',
    'Entry is fresh: signal is less than 3-5 minutes old',
    'Price is not already extended more than ~0.75% to 1.25% above VWAP',
];

export default function PickFormula() {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Dashboard', href: '/' },
                { title: 'Analysis', href: '/analysis/breakout' },
                { title: 'Pick Formula', href: '/analysis/pick-formula' },
            ]}
        >
            <Head title="Pick Formula" />

            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <h1 className="text-2xl font-bold tracking-tight">Pick Formula</h1>

                <Card>
                    <CardHeader>
                        <CardTitle>Entry Criteria</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="space-y-4">
                            {criteria.map((item, idx) => (
                                <li key={idx} className="flex items-start gap-3">
                                    <CheckCircle className="mt-0.5 h-5 w-5 shrink-0 text-green-600" />
                                    <span className="text-sm leading-relaxed">
                                        <strong className="font-semibold">{idx + 1}.</strong> {item}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
