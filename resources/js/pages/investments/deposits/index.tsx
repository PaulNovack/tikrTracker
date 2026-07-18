import {
    create,
    destroy,
    edit,
    index,
} from '@/actions/App/Http/Controllers/DepositController';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';

interface Deposit {
    id: number;
    amount: string;
    notes: string | null;
    deposited_at: string;
    created_at: string;
    updated_at: string;
}

interface Props {
    deposits: Deposit[];
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
];

export default function DepositsIndex({ deposits }: Props) {
    const handleDelete = (depositId: number) => {
        if (confirm('Are you sure you want to delete this deposit?')) {
            router.delete(destroy(depositId).url);
        }
    };

    const formatCurrency = (amount: string) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(parseFloat(amount));
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const totalDeposits = deposits.reduce(
        (sum, deposit) => sum + parseFloat(deposit.amount),
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Deposits" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Deposits
                        </h1>
                        <p className="text-muted-foreground">
                            Track all money deposited into your investment
                            account
                        </p>
                    </div>
                    <Link href={create().url}>
                        <Button>
                            <Plus className="mr-2 size-4" />
                            Add Deposit
                        </Button>
                    </Link>
                </div>

                <div className="rounded-lg border bg-card p-6">
                    <div className="mb-2 text-sm text-muted-foreground">
                        Total Deposited
                    </div>
                    <div className="text-3xl font-bold">
                        {formatCurrency(totalDeposits.toString())}
                    </div>
                </div>

                <div className="rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Amount</TableHead>
                                <TableHead>Notes</TableHead>
                                <TableHead className="text-right">
                                    Actions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {deposits.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        No deposits found. Add your first
                                        deposit to get started!
                                    </TableCell>
                                </TableRow>
                            ) : (
                                deposits.map((deposit) => (
                                    <TableRow key={deposit.id}>
                                        <TableCell>
                                            {formatDate(deposit.deposited_at)}
                                        </TableCell>
                                        <TableCell className="font-semibold">
                                            {formatCurrency(deposit.amount)}
                                        </TableCell>
                                        <TableCell>
                                            {deposit.notes || (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-2">
                                                <Link
                                                    href={edit(deposit.id).url}
                                                >
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                    >
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                </Link>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        handleDelete(deposit.id)
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
