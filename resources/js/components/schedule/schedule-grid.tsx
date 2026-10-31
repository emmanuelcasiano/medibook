import { formatClinicTime } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { DaySchedule, ScheduleAppointment } from '@/types';
import { statusStyles } from './status-styles';

/** The height of one grid row (one slot step), in rem. */
const ROW_REM = 2;

type Props = {
    schedule: DaySchedule;
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
export default function ScheduleGrid({ schedule }: Props) {
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
            className="hidden overflow-x-auto rounded-xl border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:block"
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
    startMinute: number;
    stepMinutes: number;
};

function Block({ appointment, startMinute, stepMinutes }: BlockProps) {
    const rowSpan = appointment.durationMinutes / stepMinutes;
    const short = rowSpan <= 1;

    return (
        <div
            role="listitem"
            title={`${appointment.patientName} · ${appointment.serviceName} · ${appointment.statusLabel}`}
            className={cn(
                'absolute inset-x-1 overflow-hidden rounded-md border px-2 py-1 text-xs leading-tight',
                statusStyles[appointment.status],
            )}
            style={{
                top: `${((appointment.offsetMinutes - startMinute) / stepMinutes) * ROW_REM + 0.0625}rem`,
                height: `${rowSpan * ROW_REM - 0.125}rem`,
            }}
        >
            <span className="sr-only">
                {formatClinicTime(appointment.startsAt)} to{' '}
                {formatClinicTime(appointment.endsAt)}.{' '}
            </span>
            {short ? (
                <p className="truncate">
                    <span className="font-medium">
                        {appointment.patientName}
                    </span>{' '}
                    · {appointment.statusLabel}
                </p>
            ) : (
                <>
                    <p className="truncate font-medium">
                        {appointment.patientName}
                    </p>
                    <p className="truncate">
                        {appointment.serviceName} · {appointment.statusLabel}
                    </p>
                </>
            )}
        </div>
    );
}
