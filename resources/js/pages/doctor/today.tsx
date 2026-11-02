import { Head, Link } from '@inertiajs/react';
import { CalendarDays, ChevronRight } from 'lucide-react';
import DoctorAppointmentController from '@/actions/App/Http/Controllers/Doctor/AppointmentController';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { formatClinicDate, formatClinicTime } from '@/lib/clinic-time';
import { home as doctorHome } from '@/routes/doctor';
import type { TodayAppointment } from '@/types';

type Props = {
    /** Today in the clinic time zone, 'Y-m-d'. */
    date: string;
    /** The signed-in doctor's own appointments for today, earliest first. */
    appointments: TodayAppointment[];
};

export default function DoctorToday({ date, appointments }: Props) {
    return (
        <>
            <Head title="Today" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Today"
                    description={
                        <>
                            <p>
                                {formatClinicDate(date)}. All times are Manila
                                time.
                            </p>
                            <p aria-live="polite">
                                {appointments.length}{' '}
                                {appointments.length === 1
                                    ? 'appointment'
                                    : 'appointments'}
                            </p>
                        </>
                    }
                />

                {appointments.length > 0 ? (
                    <ul className="space-y-3">
                        {appointments.map((appointment) => (
                            <li key={appointment.ulid}>
                                <Link
                                    href={DoctorAppointmentController.show(
                                        appointment.ulid,
                                    )}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border bg-card p-4 outline-none hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <span className="w-20 shrink-0 font-semibold tabular-nums">
                                        {formatClinicTime(appointment.startsAt)}
                                    </span>
                                    <span className="min-w-40 flex-1">
                                        <span className="block font-semibold">
                                            {appointment.patientFirstName}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {appointment.serviceName}
                                        </span>
                                    </span>
                                    {/* Both are written out, so neither depends on color alone. */}
                                    <StatusBadge
                                        status={appointment.status}
                                        label={appointment.statusLabel}
                                    />
                                    <Badge variant="outline">
                                        {appointment.summaryLabel}
                                    </Badge>
                                    <ChevronRight
                                        aria-hidden
                                        className="ml-auto size-4 text-muted-foreground"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <div className="flex flex-col items-center gap-3 rounded-xl border bg-card p-8 text-center">
                        <CalendarDays
                            aria-hidden
                            className="size-7 text-muted-foreground"
                        />
                        <p className="font-medium">No appointments today.</p>
                    </div>
                )}
            </div>
        </>
    );
}

DoctorToday.layout = {
    breadcrumbs: [
        {
            title: 'Today',
            href: doctorHome(),
        },
    ],
};
