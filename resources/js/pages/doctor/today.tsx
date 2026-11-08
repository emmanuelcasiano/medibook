import { Head, Link } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronRight,
    Clock,
    Info,
    Sparkles,
} from 'lucide-react';
import DoctorAppointmentController from '@/actions/App/Http/Controllers/Doctor/AppointmentController';
import UpNextCard from '@/components/doctor/up-next-card';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { formatClinicDate, formatClinicTime } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import { home as doctorHome } from '@/routes/doctor';
import type {
    AppointmentStatus,
    SummaryStatus,
    TodayAppointment,
} from '@/types';

type Props = {
    /** Today in the clinic time zone, 'Y-m-d'. */
    date: string;
    /** The signed-in doctor's own appointments for today, earliest first. */
    appointments: TodayAppointment[];
};

/** Visits that are over. Their rows are muted so the live ones stand out. */
const PAST_STATUSES: AppointmentStatus[] = [
    'completed',
    'no_show',
    'cancelled',
];

/** The patient who is waiting, else the next booking. The list arrives earliest first. */
function pickUpNext(appointments: TodayAppointment[]) {
    return (
        appointments.find(({ status }) => status === 'checked_in') ??
        appointments.find(({ status }) => status === 'booked')
    );
}

export default function DoctorToday({ date, appointments }: Props) {
    const upNext = pickUpNext(appointments);

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

                {upNext && <UpNextCard appointment={upNext} />}

                {appointments.length > 0 ? (
                    <ul className="space-y-3">
                        {appointments.map((appointment) => {
                            const past = PAST_STATUSES.includes(
                                appointment.status,
                            );

                            return (
                                <li key={appointment.ulid}>
                                    <Link
                                        href={DoctorAppointmentController.show(
                                            appointment.ulid,
                                        )}
                                        className={cn(
                                            'flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border p-4 outline-none focus-visible:ring-[3px] focus-visible:ring-ring',
                                            past
                                                ? 'bg-muted/60 hover:bg-muted'
                                                : 'bg-card hover:bg-accent/50',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'w-20 shrink-0 font-mono tabular-nums',
                                                past
                                                    ? 'text-muted-foreground'
                                                    : 'font-semibold',
                                            )}
                                        >
                                            {formatClinicTime(
                                                appointment.startsAt,
                                            )}
                                        </span>
                                        <span className="min-w-40 flex-1">
                                            <span
                                                className={cn(
                                                    'block',
                                                    past
                                                        ? 'font-medium text-muted-foreground'
                                                        : 'font-semibold',
                                                )}
                                            >
                                                {appointment.patientFirstName}
                                            </span>
                                            <span className="block text-sm text-muted-foreground">
                                                {appointment.serviceName}
                                            </span>
                                        </span>
                                        {/* The status is a badge with its written label; the summary state is plain text with an icon. */}
                                        <StatusBadge
                                            status={appointment.status}
                                            label={appointment.statusLabel}
                                        />
                                        <SummaryState
                                            status={appointment.summaryStatus}
                                            label={appointment.summaryLabel}
                                        />
                                        <ChevronRight
                                            aria-hidden
                                            className="ml-auto size-4 text-muted-foreground"
                                        />
                                    </Link>
                                </li>
                            );
                        })}
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

/** The AI summary column in words, in the AI color. The icon follows the status; the words come from the server. */
function SummaryState({
    status,
    label,
}: {
    status: SummaryStatus | null;
    label: string;
}) {
    const Icon =
        status === 'ready' ? Sparkles : status === 'pending' ? Clock : Info;

    return (
        <span className="inline-flex items-center gap-1.5 text-sm font-medium text-ai-ink">
            <Icon aria-hidden className="size-4" />
            {label}
        </span>
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
