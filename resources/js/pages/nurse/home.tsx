import { Head, Link } from '@inertiajs/react';
import { ChevronRight, HeartPulse } from 'lucide-react';
import VitalsController from '@/actions/App/Http/Controllers/Nurse/VitalsController';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { formatClinicTime } from '@/lib/clinic-time';
import { home as nurseHome } from '@/routes/nurse';
import type { VitalsQueue, VitalsQueueRow } from '@/types';

type Props = {
    /** Today's checked-in patients, in two groups. Names and times only: no reading and no health text. */
    queue: VitalsQueue;
};

export default function NurseHome({ queue }: Props) {
    const waiting = queue.needsVitals.length + queue.vitalsTaken.length;

    return (
        <>
            <Head title="Triage" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Triage"
                    description={
                        <>
                            <p>
                                Patients who have checked in today, oldest
                                first. All times are Manila time.
                            </p>
                            <p aria-live="polite">
                                {waiting}{' '}
                                {waiting === 1 ? 'patient' : 'patients'} checked
                                in
                            </p>
                        </>
                    }
                />

                <QueueSection
                    id="needs-vitals"
                    title="Needs vitals"
                    rows={queue.needsVitals}
                    action="Record vitals"
                    empty="No patients are waiting for vitals."
                />

                <QueueSection
                    id="vitals-taken"
                    title="Vitals taken, waiting for the doctor"
                    rows={queue.vitalsTaken}
                    action="Edit vitals"
                    empty="No one yet."
                    quiet
                />
            </div>
        </>
    );
}

function QueueSection({
    id,
    title,
    rows,
    action,
    empty,
    quiet = false,
}: {
    id: string;
    title: string;
    rows: VitalsQueueRow[];
    action: string;
    empty: string;
    /** The second group is done work, so its empty state is a line and not a card. */
    quiet?: boolean;
}) {
    return (
        <section aria-labelledby={`${id}-heading`} className="space-y-3">
            <h2
                id={`${id}-heading`}
                className="flex items-center gap-2 text-lg font-semibold"
            >
                {title}
                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground">
                    {rows.length}
                </span>
            </h2>

            {rows.length > 0 ? (
                <ul className="space-y-3">
                    {rows.map((row) => (
                        <li
                            key={row.ulid}
                            className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border bg-card p-4"
                        >
                            <span className="w-20 shrink-0 font-mono font-semibold tabular-nums">
                                {formatClinicTime(row.startsAt)}
                            </span>
                            <span className="min-w-40 flex-1">
                                <span className="block font-semibold">
                                    {row.patientName}
                                </span>
                                <span className="block text-sm text-muted-foreground">
                                    {row.serviceName} · {row.doctorName}
                                </span>
                            </span>
                            <Button
                                asChild
                                variant={quiet ? 'outline' : 'default'}
                            >
                                <Link
                                    href={VitalsController.edit(row.ulid)}
                                    data-test="vitals-link"
                                >
                                    {action}
                                    <span className="sr-only">
                                        {' '}
                                        for {row.patientName}
                                    </span>
                                    <ChevronRight aria-hidden />
                                </Link>
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : quiet ? (
                <p className="text-sm text-muted-foreground">{empty}</p>
            ) : (
                <div className="flex flex-col items-center gap-3 rounded-xl border bg-card p-8 text-center">
                    <HeartPulse
                        aria-hidden
                        className="size-7 text-muted-foreground"
                    />
                    <p className="font-medium">{empty}</p>
                </div>
            )}
        </section>
    );
}

NurseHome.layout = {
    breadcrumbs: [
        {
            title: 'Triage',
            href: nurseHome(),
        },
    ],
};
