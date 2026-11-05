import { Head } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import AiUsageController from '@/actions/App/Http/Controllers/Admin/AiUsageController';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { formatClinicMonth } from '@/lib/clinic-time';
import type { AdminAiUsage } from '@/types';

type Props = {
    usage: AdminAiUsage;
};

const numberFormat = new Intl.NumberFormat('en-PH');

function Tile({
    label,
    value,
    children,
}: {
    label: string;
    value: string;
    children?: ReactNode;
}) {
    return (
        <div className="rounded-xl border bg-card p-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-1 text-2xl font-semibold tracking-tight">
                {value}
            </dd>
            {children && (
                <div className="mt-2 space-y-0.5 text-sm text-muted-foreground">
                    {children}
                </div>
            )}
        </div>
    );
}

export default function AdminAiUsageIndex({ usage }: Props) {
    const { cap, tokens, statuses, estimatedCost } = usage;
    const month = formatClinicMonth(usage.month);
    const percentUsed =
        cap.daily > 0 ? Math.min(100, (cap.usedToday / cap.daily) * 100) : 100;

    return (
        <>
            <Head title="AI usage" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="AI usage"
                    description={
                        <p className="flex items-center gap-1.5">
                            <Lock className="size-3.5 shrink-0" aria-hidden />
                            Summaries made in {month}. Counts and totals only:
                            no intake text, summary text or patient names.
                        </p>
                    }
                />

                <section
                    aria-labelledby="cap-heading"
                    className="space-y-3 rounded-xl border bg-card p-4"
                >
                    <h2 id="cap-heading" className="font-medium">
                        Daily token cap
                    </h2>
                    <div
                        role="meter"
                        aria-labelledby="cap-heading"
                        aria-valuemin={0}
                        aria-valuemax={cap.daily}
                        aria-valuenow={Math.min(cap.usedToday, cap.daily)}
                        aria-valuetext={`${numberFormat.format(cap.usedToday)} of ${numberFormat.format(cap.daily)} tokens used today`}
                        className="h-2 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            className={
                                cap.reached
                                    ? 'h-full bg-destructive'
                                    : 'h-full bg-primary'
                            }
                            style={{ width: `${percentUsed}%` }}
                        />
                    </div>
                    <p className="text-sm">
                        <span className="font-medium">
                            {numberFormat.format(cap.usedToday)}
                        </span>{' '}
                        of {numberFormat.format(cap.daily)} tokens used today.
                    </p>
                    {cap.reached ? (
                        <p className="text-sm font-medium text-destructive">
                            Cap reached. New summaries are skipped until
                            tomorrow, and the raw answers are still shown.
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            When the cap is reached, new summaries are skipped
                            until tomorrow (clinic time).
                        </p>
                    )}
                </section>

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Tile
                        label={`Total tokens in ${month}`}
                        value={numberFormat.format(tokens.total)}
                    >
                        <p>Input: {numberFormat.format(tokens.input)}</p>
                        <p>Output: {numberFormat.format(tokens.output)}</p>
                    </Tile>
                    <Tile
                        label={`Estimated cost in ${month}`}
                        value={
                            estimatedCost === null
                                ? 'Price not set'
                                : `$${estimatedCost}`
                        }
                    >
                        <p>
                            {estimatedCost === null
                                ? 'Set the input and output price per million tokens in the server settings to see a cost.'
                                : 'US dollars. An estimate from the prices in the server settings, not a bill.'}
                        </p>
                    </Tile>
                </dl>

                <div className="overflow-x-auto rounded-xl border bg-card">
                    <table className="w-full text-sm">
                        <caption className="sr-only">
                            Summaries made in {month}, by status
                        </caption>
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 text-right font-medium"
                                >
                                    Summaries
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {statuses.map((row) => (
                                <tr key={row.status}>
                                    <th
                                        scope="row"
                                        className="px-4 py-3 text-left font-normal"
                                    >
                                        <StatusBadge
                                            status={row.status}
                                            label={row.label}
                                        />
                                    </th>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {numberFormat.format(row.count)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

AdminAiUsageIndex.layout = {
    breadcrumbs: [
        {
            title: 'AI usage',
            href: AiUsageController.index(),
        },
    ],
};
