import { Link } from '@inertiajs/react';
import ScheduleController from '@/actions/App/Http/Controllers/Staff/ScheduleController';
import { formatClinicTime } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { DaySchedule, ScheduleAppointment } from '@/types';
import AppointmentFlags from './appointment-flags';
import { statusStyles } from './status-styles';

/** The height of one grid row (one slot step), in rem. */
const ROW_REM = 2;

type Props = {
    schedule: DaySchedule;
    /** The booking whose side panel is open, if any. */
    selectedUlid: string | null;
};

/** A time of day from minutes after clinic midnight, as "9:00 am". */
function minuteLabel(minute: number): string {
    const hours = Math.floor(minute / 60) % 24;
    const suffix = hours < 12 ? 'am' : 'pm';

    return `${hours % 12 === 0 ? 12 : hours % 12}:00 ${suffix}`;
}

/**
 * The day grid for screens from `md` up: a column per doctor and a row per slot step. A booking is a block placed at
 * its start and as tall as its visit. On a phone the per-doctor list is shown instead.
 */
export default function ScheduleGrid({ schedule, selectedUlid }: Props) {
    const { doctors, startMinute, endMinute, stepMinutes } = schedule;
    const rows = (endMinute - startMinute) / stepMinutes;
    const height = `${rows * ROW_REM}rem`;
    const hourRem = (60 / stepMinutes) * ROW_REM;

    const hours: number[] = [];

    for (let minute = startMinute; minute < endMinute; minute += 60) {
        hours.push(minute);
    }

    // A fine line for every row and a stronger one for every hour.
    const lines = [
        `repeating-linear-gradient(to bottom, color-mix(in oklab, var(--muted-foreground) 45%, transparent) 0, color-mix(in oklab, var(--muted-foreground) 45%, transparent) 1px, transparent 1px, transparent ${hourRem}rem)`,
        `repeating-linear-gradient(to bottom, var(--border) 0, var(--border) 1px, transparent 1px, transparent ${ROW_REM}rem)`,
    ].join(', ');

    return (
        // The grid scrolls sideways on a narrow window. A scroll area needs a tab stop to be scrolled with the keyboard.
        <div
            role="region"
            aria-label="Day schedule grid"
            tabIndex={0}
            className="hidden overflow-x-auto rounded-xl border bg-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:block"
        >
            <div
                className="grid min-w-[44rem]"
                style={{
                    gridTemplateColumns: `4rem repeat(${doctors.length}, minmax(11rem, 1fr))`,
                }}
            >
                <div className="border-b" aria-hidden />
                {doctors.map((doctor) => (
                    <div key={doctor.id} className="border-b border-l p-3">
                        <p className="font-medium">{doctor.name}</p>
                        <p className="text-xs text-muted-foreground">
                            {doctor.specialty} · {doctor.appointments.length}{' '}
                            {doctor.appointments.length === 1
                                ? 'appointment'
                                : 'appointments'}
                            {!doctor.isActive && ' · Deactivated'}
                            {!doctor.isWorking && ' · Not working today'}
                        </p>
                    </div>
                ))}

                <div className="relative" style={{ height }} aria-hidden>
                    {hours.map((minute) => (
                        <span
                            key={minute}
                            className="absolute right-2 text-xs text-muted-foreground"
                            style={{
                                top: `${((minute - startMinute) / stepMinutes) * ROW_REM + 0.125}rem`,
                            }}
                        >
                            {minuteLabel(minute)}
                        </span>
                    ))}
                </div>

                {doctors.map((doctor) => (
                    <div
                        key={doctor.id}
                        role="list"
                        aria-label={`Appointments of ${doctor.name}`}
                        className="relative border-l"
                        style={{ height, backgroundImage: lines }}
                    >
                        {doctor.appointments.map((appointment) => (
                            <Block
                                key={appointment.ulid}
                                appointment={appointment}
                                date={schedule.date}
                                selected={appointment.ulid === selectedUlid}
                                startMinute={startMinute}
                                stepMinutes={stepMinutes}
                            />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}

type BlockProps = {
    appointment: ScheduleAppointment;
    /** The clinic day on the page, so the link stays on it. */
    date: string;
    selected: boolean;
    startMinute: number;
    stepMinutes: number;
};

function Block({
    appointment,
    date,
    selected,
    startMinute,
    stepMinutes,
}: BlockProps) {
    const rowSpan = appointment.durationMinutes / stepMinutes;
    const short = rowSpan <= 1;

    return (
        <div
            role="listitem"
            className={cn(
                'absolute inset-x-1 overflow-hidden rounded-md border text-xs leading-tight',
                statusStyles[appointment.status],
                selected && 'ring-2 ring-ring',
            )}
            style={{
                top: `${((appointment.offsetMinutes - startMinute) / stepMinutes) * ROW_REM + 0.0625}rem`,
                height: `${rowSpan * ROW_REM - 0.125}rem`,
            }}
        >
            {/* The whole block opens the side panel. A real link, so Tab and Enter work, and the address can be shared. */}
            <Link
                href={ScheduleController.index({
                    query: { date, appointment: appointment.ulid },
                })}
                preserveScroll
                preserveState
                aria-current={selected ? 'true' : undefined}
                data-appointment={appointment.ulid}
                title={`${appointment.patientName} · ${appointment.serviceName} · ${appointment.statusLabel}`}
                className="block size-full rounded-md px-2 py-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
            >
                <span className="sr-only">
                    {formatClinicTime(appointment.startsAt)} to{' '}
                    {formatClinicTime(appointment.endsAt)}.{' '}
                </span>
                {short ? (
                    <p className="flex items-center gap-1">
                        <span className="truncate">
                            <span className="font-medium">
                                {appointment.patientName}
                            </span>{' '}
                            · {appointment.statusLabel}
                        </span>
                        <AppointmentFlags
                            appointment={appointment}
                            className="shrink-0"
                        />
                    </p>
                ) : (
                    <>
                        <p className="flex items-center gap-1">
                            <span className="truncate font-medium">
                                {appointment.patientName}
                            </span>
                            <AppointmentFlags
                                appointment={appointment}
                                className="shrink-0"
                            />
                        </p>
                        <p className="truncate">
                            {appointment.serviceName} ·{' '}
                            {appointment.statusLabel}
                        </p>
                    </>
                )}
            </Link>
        </div>
    );
}
