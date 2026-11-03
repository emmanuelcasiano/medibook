import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ScheduleController from '@/actions/App/Http/Controllers/Staff/ScheduleController';
import { formatClinicTime } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { DaySchedule, ScheduleAppointment, ScheduleDoctor } from '@/types';
import AppointmentFlags from './appointment-flags';
import { selectedStyle, statusStyles } from './status-styles';

/** The height of one grid row (one slot step), in rem. */
const ROW_REM = 2;
/** The width of the pinned time column, and the narrowest a doctor column gets before the grid scrolls sideways. */
const TIME_COLUMN_REM = 4;
const DOCTOR_COLUMN_REM = 9.5;

/** The grey hatching of the mockup, for the column of a doctor who is not working that day. */
const OFF_HATCH =
    'repeating-linear-gradient(135deg, var(--muted) 0 6px, color-mix(in oklab, var(--muted) 70%, var(--border)) 6px 12px)';

type Props = {
    schedule: DaySchedule;
    /** The doctors to draw, already narrowed by the chips and the off-today toggle. */
    doctors: ScheduleDoctor[];
    /** The booking whose side panel is open, if any. */
    selectedUlid: string | null;
    /** The panel opens over the right edge of the grid (a slide-over), so a booking must not be scrolled under it. */
    panelOverlaps: boolean;
};

/** A time of day from minutes after clinic midnight, as "9:30 am". */
function timeLabel(minute: number, withMinutes = true): string {
    const hours = Math.floor(minute / 60) % 24;
    const suffix = hours < 12 ? 'am' : 'pm';
    const minutes = String(minute % 60).padStart(2, '0');

    return `${hours % 12 === 0 ? 12 : hours % 12}${withMinutes ? `:${minutes}` : ''} ${suffix}`;
}

const clinicClock = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Manila',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

/** Minutes after midnight, on the clinic's clock. */
function clinicMinuteNow(): number {
    const parts = clinicClock.formatToParts(new Date());
    const part = (type: string) =>
        Number(parts.find((candidate) => candidate.type === type)?.value ?? 0);

    return (part('hour') % 24) * 60 + part('minute');
}

/** The clinic's minute of the day, kept fresh once a minute, or null when it is not needed (not today's grid). */
function useClinicMinute(enabled: boolean): number | null {
    const [minute, setMinute] = useState(clinicMinuteNow);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const timer = window.setInterval(
            () => setMinute(clinicMinuteNow()),
            30_000,
        );

        return () => window.clearInterval(timer);
    }, [enabled]);

    return enabled ? minute : null;
}

/**
 * The day grid for screens from `md` up: a column per doctor and a row per slot step. A booking is a block placed at
 * its start and as tall as its visit. On a phone the per-doctor list is shown instead.
 *
 * The grid is its own scroll box (both ways), because a sticky header only sticks to the box that scrolls: the doctor
 * names stay in view while scrolling down and the times stay in view while scrolling sideways.
 */
export default function ScheduleGrid({
    schedule,
    doctors,
    selectedUlid,
    panelOverlaps,
}: Props) {
    const { startMinute, endMinute, stepMinutes } = schedule;
    const rows = (endMinute - startMinute) / stepMinutes;
    const height = `${rows * ROW_REM}rem`;
    const hourRem = (60 / stepMinutes) * ROW_REM;
    const scroller = useRef<HTMLDivElement>(null);
    const scrolledDate = useRef<string | null>(null);

    const hours: number[] = [];

    for (let minute = startMinute; minute < endMinute; minute += 60) {
        hours.push(minute);
    }

    // A line for every hour and a lighter one for every half hour.
    const lines = [
        `repeating-linear-gradient(to bottom, color-mix(in oklab, var(--muted-foreground) 45%, transparent) 0, color-mix(in oklab, var(--muted-foreground) 45%, transparent) 1px, transparent 1px, transparent ${hourRem}rem)`,
        `repeating-linear-gradient(to bottom, var(--border) 0, var(--border) 1px, transparent 1px, transparent ${hourRem / 2}rem)`,
    ];

    const isToday = schedule.date === schedule.today;
    const nowMinute = useClinicMinute(isToday);
    const showNow =
        nowMinute !== null && nowMinute >= startMinute && nowMinute < endMinute;
    const nowTop = showNow
        ? `${((nowMinute - startMinute) / stepMinutes) * ROW_REM}rem`
        : undefined;

    const firstOffset = doctors
        .flatMap((doctor) => doctor.appointments)
        .reduce<number | null>(
            (first, appointment) =>
                first === null || appointment.offsetMinutes < first
                    ? appointment.offsetMinutes
                    : first,
            null,
        );

    // Bring the booking that matters into view. The open booking wins. Otherwise a new day starts at its first
    // booking, not at the top of the working hours. Closing a panel or filtering must not move the grid.
    useEffect(() => {
        const box = scroller.current;
        const dayChanged = scrolledDate.current !== schedule.date;

        scrolledDate.current = schedule.date;

        if (!box) {
            return;
        }

        if (selectedUlid) {
            box.querySelector<HTMLElement>(
                `[data-appointment="${selectedUlid}"]`,
            )?.scrollIntoView({ block: 'nearest', inline: 'nearest' });

            return;
        }

        if (dayChanged) {
            const rem =
                parseFloat(
                    getComputedStyle(document.documentElement).fontSize,
                ) || 16;
            const rowsAbove =
                firstOffset === null
                    ? 0
                    : (firstOffset - startMinute) / stepMinutes - 1;

            box.scrollTo({
                top: Math.max(0, rowsAbove * ROW_REM * rem),
                left: 0,
            });
        }
    }, [schedule.date, selectedUlid, firstOffset, startMinute, stepMinutes]);

    if (doctors.length === 0) {
        return (
            <p className="hidden rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground md:block">
                No doctors to show. Everyone who works today is filtered out, or
                every doctor is off today. Change the chips or use the toggle
                above.
            </p>
        );
    }

    return (
        // A scroll area needs a tab stop to be scrolled with the keyboard. The scroll padding keeps a block that is
        // scrolled into view clear of the pinned headers and, when the panel slides over the grid, of the panel.
        <div
            ref={scroller}
            role="region"
            aria-label="Day schedule grid"
            tabIndex={0}
            className={cn(
                'hidden max-h-[max(24rem,calc(100dvh-10rem))] scroll-pt-24 scroll-pl-20 overflow-auto rounded-xl border bg-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:block',
                panelOverlaps && 'scroll-pr-96',
            )}
        >
            {/* The spacer after the columns is room to scroll the last column clear of a slide-over panel. Without it a
                booking in the last column can sit under the panel with nowhere to scroll to. */}
            <div className="flex">
                <div
                    className="grid flex-1"
                    style={{
                        gridTemplateColumns: `${TIME_COLUMN_REM}rem repeat(${doctors.length}, minmax(${DOCTOR_COLUMN_REM}rem, 1fr))`,
                        minWidth: `${TIME_COLUMN_REM + doctors.length * DOCTOR_COLUMN_REM}rem`,
                    }}
                >
                    <div
                        className="sticky top-0 left-0 z-30 border-r border-b bg-card"
                        aria-hidden
                    />
                    {doctors.map((doctor) => (
                        <div
                            key={doctor.id}
                            className="sticky top-0 z-20 min-w-0 border-b border-l bg-card px-2 py-2"
                        >
                            <p
                                className="truncate text-sm font-medium"
                                title={doctor.name}
                            >
                                {doctor.name}
                            </p>
                            <p
                                className="truncate text-xs text-muted-foreground"
                                title={doctor.specialty}
                            >
                                {doctor.specialty}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {doctor.appointments.length}{' '}
                                {doctor.appointments.length === 1
                                    ? 'booking'
                                    : 'bookings'}
                                {!doctor.isActive && ' · Deactivated'}
                                {!doctor.isWorking && ' · Off today'}
                            </p>
                        </div>
                    ))}

                    <div
                        className="sticky left-0 z-10 border-r bg-card"
                        style={{ height }}
                        aria-hidden
                    >
                        {hours.map((minute) => (
                            <span
                                key={minute}
                                className="absolute right-2 text-xs text-muted-foreground"
                                style={{
                                    top: `${((minute - startMinute) / stepMinutes) * ROW_REM + 0.125}rem`,
                                }}
                            >
                                {timeLabel(minute, false)}
                            </span>
                        ))}
                        {showNow && nowMinute !== null && (
                            <span
                                className="absolute right-1 z-10 -translate-y-1/2 rounded bg-red-600 px-1 text-[0.65rem] leading-4 font-medium text-white"
                                style={{ top: nowTop }}
                            >
                                {timeLabel(nowMinute)}
                            </span>
                        )}
                    </div>

                    {doctors.map((doctor) => (
                        <div
                            key={doctor.id}
                            role="list"
                            aria-label={`Appointments of ${doctor.name}`}
                            className="relative min-w-0 border-l"
                            style={{
                                height,
                                backgroundImage: (doctor.isWorking
                                    ? lines
                                    : [...lines, OFF_HATCH]
                                ).join(', '),
                            }}
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
                            {showNow && (
                                <div
                                    aria-hidden
                                    className="pointer-events-none absolute inset-x-0 z-5 border-t border-red-600"
                                    style={{ top: nowTop }}
                                />
                            )}
                        </div>
                    ))}
                </div>
                {panelOverlaps && <div aria-hidden className="w-96 shrink-0" />}
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
                selected && selectedStyle,
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
