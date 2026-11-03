import { useId } from 'react';
import {
    formatClinicDateTime,
    formatClinicInstantDay,
    formatClinicTime,
} from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { BookableService } from '@/types';

type Props = {
    heading: string;
    service: BookableService;
    /** "Any available doctor" until the booking is made, then the doctor's name. */
    doctorName: string;
    doctorSpecialty?: string | null;
    /** ISO 8601 UTC strings, as the server sends them. */
    startsAt: string;
    endsAt: string;
    cancelUntil: string;
    className?: string;
};

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-[5.5rem_1fr] gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium">{children}</dd>
        </div>
    );
}

/** The visit in one card: service, doctor, day, time, length and the last moment to cancel for free. */
export default function BookingSummary({
    heading,
    service,
    doctorName,
    doctorSpecialty,
    startsAt,
    endsAt,
    cancelUntil,
    className,
}: Props) {
    const headingId = useId();

    return (
        <section
            aria-labelledby={headingId}
            className={cn('rounded-xl border bg-card', className)}
        >
            <div className="space-y-4 p-5">
                <h2 id={headingId} className="text-lg font-semibold">
                    {heading}
                </h2>

                <dl className="space-y-3 text-[15px]">
                    <Row label="Service">{service.name}</Row>
                    <Row label="Doctor">
                        {doctorName}
                        {doctorSpecialty && (
                            <span className="block text-sm font-normal text-muted-foreground">
                                {doctorSpecialty}
                            </span>
                        )}
                    </Row>
                    <Row label="Date">{formatClinicInstantDay(startsAt)}</Row>
                    <Row label="Time">
                        <span className="font-mono">
                            {formatClinicTime(startsAt)} to{' '}
                            {formatClinicTime(endsAt)}
                        </span>
                    </Row>
                    <Row label="Length">{service.durationMinutes} min</Row>
                </dl>
            </div>

            <p className="rounded-b-xl border-t bg-muted/50 px-5 py-3 text-sm text-muted-foreground">
                Free to cancel until{' '}
                <strong className="text-foreground">
                    {formatClinicDateTime(cancelUntil)}
                </strong>
                .
            </p>
        </section>
    );
}
