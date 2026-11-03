import { cn } from '@/lib/utils';
import type { ScheduleDoctor } from '@/types';

/** A doctor who does not work that day and has no booking. A doctor with a booking is never "off", so never hidden. */
export function isOffToday(doctor: ScheduleDoctor): boolean {
    return !doctor.isWorking && doctor.appointments.length === 0;
}

/** The doctors staff asked for. No chip chosen means everyone, which is the "All" chip. */
export function chosenDoctors(
    doctors: ScheduleDoctor[],
    chosenIds: number[],
): ScheduleDoctor[] {
    return chosenIds.length === 0
        ? doctors
        : doctors.filter((doctor) => chosenIds.includes(doctor.id));
}

type Props = {
    /** Every doctor of the day, off or not, so a chip can bring an off doctor back. */
    doctors: ScheduleDoctor[];
    chosenIds: number[];
    onChange: (ids: number[]) => void;
    /** How many doctors the grid hides because they are off today. */
    offCount: number;
    showOff: boolean;
    onShowOffChange: (show: boolean) => void;
};

const chip =
    'inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/**
 * The doctor chips above the schedule ("All" or one or more doctors), and the toggle for the doctors who are off today.
 * Both are UI state held by the page, so they stay when the day changes. Real buttons with `aria-pressed`, so the
 * keyboard and screen readers get the same choices as the mouse.
 */
export default function DoctorFilter({
    doctors,
    chosenIds,
    onChange,
    offCount,
    showOff,
    onShowOffChange,
}: Props) {
    function toggle(id: number) {
        const next = chosenIds.includes(id)
            ? chosenIds.filter((chosen) => chosen !== id)
            : [...chosenIds, id];

        onChange(next);
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div
                role="group"
                aria-label="Show doctors"
                className="flex flex-wrap items-center gap-2"
            >
                <button
                    type="button"
                    aria-pressed={chosenIds.length === 0}
                    onClick={() => onChange([])}
                    className={cn(
                        chip,
                        chosenIds.length === 0
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'bg-card hover:bg-accent',
                    )}
                >
                    All
                </button>
                {doctors.map((doctor) => {
                    const pressed = chosenIds.includes(doctor.id);

                    return (
                        <button
                            key={doctor.id}
                            type="button"
                            aria-pressed={pressed}
                            onClick={() => toggle(doctor.id)}
                            className={cn(
                                chip,
                                pressed
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'bg-card hover:bg-accent',
                            )}
                        >
                            {doctor.name}
                            {isOffToday(doctor) && (
                                <span
                                    className={cn(
                                        'text-xs font-normal',
                                        pressed
                                            ? 'text-primary-foreground/80'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    off
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>

            {/* Only the grid hides doctors. The phone list keeps them, last and closed. A chosen doctor is always shown. */}
            {offCount > 0 && chosenIds.length === 0 && (
                <button
                    type="button"
                    aria-pressed={showOff}
                    onClick={() => onShowOffChange(!showOff)}
                    className={cn(
                        chip,
                        'hidden bg-card text-muted-foreground hover:bg-accent md:inline-flex',
                    )}
                >
                    {offCount} {offCount === 1 ? 'doctor' : 'doctors'} off today
                    <span className="font-semibold text-foreground">
                        {showOff ? 'Hide' : 'Show'}
                    </span>
                </button>
            )}
        </div>
    );
}
