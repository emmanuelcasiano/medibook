import { Link } from '@inertiajs/react';
import { ArrowRight, Repeat } from 'lucide-react';
import FollowUpBookingController from '@/actions/App/Http/Controllers/Patient/FollowUpBookingController';
import { Button } from '@/components/ui/button';
import { formatClinicDate } from '@/lib/clinic-time';
import type { MyFollowUp } from '@/types';

type Props = {
    followUp: MyFollowUp;
    /** False at the limit of upcoming appointments. The server still refuses a booking over the limit. */
    canBook: boolean;
};

/** A visit a doctor asked the patient to book. The words say what it is, so it never depends on color alone. */
export default function FollowUpCard({ followUp, canBook }: Props) {
    const { service, doctor, windowStartsOn, windowEndsOn, note } = followUp;
    const headingId = `follow-up-${followUp.ulid}`;

    return (
        <li
            aria-labelledby={headingId}
            className="flex flex-col gap-4 rounded-xl border border-amber-line bg-amber-soft p-4 text-amber-ink sm:flex-row sm:items-center"
        >
            <Repeat aria-hidden className="hidden size-6 shrink-0 sm:block" />

            <div className="min-w-0 flex-1 space-y-1">
                <p className="text-xs font-semibold tracking-wider uppercase">
                    Follow-up to book
                </p>
                <h3 id={headingId} className="text-base font-semibold">
                    {doctor === null
                        ? 'The clinic would like to see you again'
                        : `${doctor.name} would like to see you again`}
                </h3>
                <p className="text-sm">
                    {service.name} · {service.durationMinutes} min ·{' '}
                    {doctor === null ? 'Any doctor' : doctor.specialty}
                </p>
                <p className="text-sm">
                    Come back between{' '}
                    <strong>{formatClinicDate(windowStartsOn)}</strong> and{' '}
                    <strong>{formatClinicDate(windowEndsOn)}</strong>.
                </p>
                {note !== null && note !== '' && (
                    <p className="text-sm whitespace-pre-line">
                        <span className="font-semibold">Note: </span>
                        {note}
                    </p>
                )}
            </div>

            {canBook ? (
                <Button asChild className="shrink-0">
                    <Link href={FollowUpBookingController.slots(followUp.ulid)}>
                        Book follow-up
                        <ArrowRight aria-hidden />
                    </Link>
                </Button>
            ) : (
                <Button
                    disabled
                    aria-describedby="book-limit-note"
                    className="shrink-0"
                >
                    Book follow-up
                    <ArrowRight aria-hidden />
                </Button>
            )}
        </li>
    );
}
