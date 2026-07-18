import {
    index,
    update,
} from '@/actions/App/Http/Controllers/DepositController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Form, Head, Link } from '@inertiajs/react';

interface Deposit {
    id: number;
    amount: string;
    notes: string | null;
    deposited_at: string;
}

interface Props {
    deposit: Deposit;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Investments',
        href: '#',
    },
    {
        title: 'Deposits',
        href: index().url,
    },
    {
        title: 'Edit Deposit',
        href: '#',
    },
];

export default function DepositsEdit({ deposit }: Props) {
    const formatDateForInput = (dateString: string) => {
        return new Date(dateString).toISOString().slice(0, 16);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Deposit" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Edit Deposit
                    </h1>
                    <p className="text-muted-foreground">
                        Update deposit information
                    </p>
                </div>

                <div className="max-w-2xl rounded-lg border bg-card p-6">
                    <Form
                        action={update(deposit.id).url}
                        method="patch"
                        className="space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="amount">Amount ($)</Label>
                                    <Input
                                        id="amount"
                                        name="amount"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        required
                                        defaultValue={deposit.amount}
                                    />
                                    <InputError message={errors.amount} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="deposited_at">
                                        Deposit Date
                                    </Label>
                                    <Input
                                        id="deposited_at"
                                        name="deposited_at"
                                        type="datetime-local"
                                        required
                                        defaultValue={formatDateForInput(
                                            deposit.deposited_at,
                                        )}
                                    />
                                    <InputError message={errors.deposited_at} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="notes">
                                        Notes (Optional)
                                    </Label>
                                    <textarea
                                        id="notes"
                                        name="notes"
                                        rows={3}
                                        className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                        defaultValue={deposit.notes || ''}
                                    />
                                    <InputError message={errors.notes} />
                                </div>

                                <div className="flex gap-3">
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Updating...'
                                            : 'Update Deposit'}
                                    </Button>
                                    <Link href={index().url}>
                                        <Button type="button" variant="outline">
                                            Cancel
                                        </Button>
                                    </Link>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </AppLayout>
    );
}
