import { Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import WorkingHourController from '@/actions/App/Http/Controllers/Admin/WorkingHourController';
import WeekSummary from '@/components/admin/week-summary';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AdminWorkingHoursDoctor, AdminWorkingHourRange } from '@/types';

type Props = {
    doctor: AdminWorkingHoursDoctor;
    ranges: AdminWorkingHourRange[];
};

type RangeInput = {
    weekday: number;
    starts_at: string;
    ends_at: string;
};

// Monday first, as a clinic week reads. The numbers are the stored weekdays (0 = Sunday).
const DAYS = [
    { weekday: 1, name: 'Monday' },
    { weekday: 2, name: 'Tuesday' },
    { weekday: 3, name: 'Wednesday' },
    { weekday: 4, name: 'Thursday' },
    { weekday: 5, name: 'Friday' },
    { weekday: 6, name: 'Saturday' },
    { weekday: 0, name: 'Sunday' },
];

/** Add minutes to an `HH:mm` time and stop at 23:59, so a new row never runs past midnight. */
function addMinutes(time: string, minutes: number): string {
    const [hours, mins] = time.split(':').map(Number);
    const total = Math.min(hours * 60 + mins + minutes, 23 * 60 + 59);

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

export default function WorkingHoursForm({ doctor, ranges }: Props) {
    // `useForm` (not `<Form>`) because an empty week must still be sent: with no row left, a plain HTML form
    // sends nothing, and the server needs to know the admin chose "closed every day".
    const form = useForm<{ ranges: RangeInput[] }>({
        ranges: ranges.map((range) => ({
            weekday: range.weekday,
            starts_at: range.startsAt,
            ends_at: range.endsAt,
        })),
    });

    function addRange(weekday: number) {
        const onThisDay = form.data.ranges.filter(
            (range) => range.weekday === weekday,
        );
        // The first range starts the morning. Later ones start where the day's last range ends.
        const startsAt =
            onThisDay.length === 0
                ? '08:00'
                : onThisDay.reduce(
                      (latest, range) =>
                          range.ends_at > latest ? range.ends_at : latest,
                      '00:00',
                  );

        form.setData('ranges', [
            ...form.data.ranges,
            {
                weekday,
                starts_at: startsAt,
                ends_at: addMinutes(
                    startsAt,
                    onThisDay.length === 0 ? 240 : 60,
                ),
            },
        ]);
    }

    function changeRange(
        index: number,
        field: 'starts_at' | 'ends_at',
        value: string,
    ) {
        form.setData(
            'ranges',
            form.data.ranges.map((range, position) =>
                position === index ? { ...range, [field]: value } : range,
            ),
        );
    }

    function removeRange(index: number) {
        form.setData(
            'ranges',
            form.data.ranges.filter((_, position) => position !== index),
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.submit(WorkingHourController.update(doctor.id));
    }

    // A rule that fails on the list itself (too many rows) comes back as `ranges`.
    const listError = form.errors.ranges;

    return (
        <form
            onSubmit={submit}
            className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]"
        >
            <div className="grid gap-6">
                <div className="divide-y rounded-xl border bg-card shadow-xs">
                    {DAYS.map((day) => {
                        const rows = form.data.ranges
                            .map((range, index) => ({ range, index }))
                            .filter(
                                ({ range }) => range.weekday === day.weekday,
                            );

                        return (
                            <fieldset
                                key={day.weekday}
                                className="flex flex-wrap items-start gap-x-4 gap-y-3 px-5 py-4"
                            >
                                <legend className="sr-only">{day.name}</legend>

                                <h3
                                    className="w-28 shrink-0 pt-2.5 font-semibold"
                                    aria-hidden="true"
                                >
                                    {day.name}
                                </h3>

                                <div className="grid min-w-64 flex-1 gap-3">
                                    {rows.length === 0 ? (
                                        <p className="pt-2.5 text-sm text-muted-foreground">
                                            Closed
                                        </p>
                                    ) : (
                                        <ul className="grid gap-3">
                                            {rows.map(
                                                (
                                                    { range, index },
                                                    position,
                                                ) => {
                                                    const label = `${day.name} range ${position + 1}`;
                                                    const startError =
                                                        form.errors[
                                                            `ranges.${index}.starts_at`
                                                        ];
                                                    const endError =
                                                        form.errors[
                                                            `ranges.${index}.ends_at`
                                                        ];
                                                    const rowError =
                                                        form.errors[
                                                            `ranges.${index}.weekday`
                                                        ] ??
                                                        form.errors[
                                                            `ranges.${index}`
                                                        ];

                                                    return (
                                                        <li
                                                            key={index}
                                                            className="grid gap-2"
                                                        >
                                                            <div className="flex flex-wrap items-end gap-3">
                                                                <div className="grid gap-1.5">
                                                                    <Label
                                                                        htmlFor={`start-${index}`}
                                                                    >
                                                                        From
                                                                        <span className="sr-only">
                                                                            {' '}
                                                                            (
                                                                            {
                                                                                label
                                                                            }
                                                                            )
                                                                        </span>
                                                                    </Label>
                                                                    <Input
                                                                        id={`start-${index}`}
                                                                        type="time"
                                                                        required
                                                                        className="w-32"
                                                                        value={
                                                                            range.starts_at
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            changeRange(
                                                                                index,
                                                                                'starts_at',
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        aria-invalid={
                                                                            startError
                                                                                ? true
                                                                                : undefined
                                                                        }
                                                                    />
                                                                </div>
                                                                <div className="grid gap-1.5">
                                                                    <Label
                                                                        htmlFor={`end-${index}`}
                                                                    >
                                                                        To
                                                                        <span className="sr-only">
                                                                            {' '}
                                                                            (
                                                                            {
                                                                                label
                                                                            }
                                                                            )
                                                                        </span>
                                                                    </Label>
                                                                    <Input
                                                                        id={`end-${index}`}
                                                                        type="time"
                                                                        required
                                                                        className="w-32"
                                                                        value={
                                                                            range.ends_at
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            changeRange(
                                                                                index,
                                                                                'ends_at',
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        aria-invalid={
                                                                            endError
                                                                                ? true
                                                                                : undefined
                                                                        }
                                                                    />
                                                                </div>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-11"
                                                                    onClick={() =>
                                                                        removeRange(
                                                                            index,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 />
                                                                    <span className="sr-only">
                                                                        Remove{' '}
                                                                        {label}
                                                                    </span>
                                                                </Button>
                                                            </div>
                                                            <InputError
                                                                message={
                                                                    startError
                                                                }
                                                            />
                                                            <InputError
                                                                message={
                                                                    endError
                                                                }
                                                            />
                                                            <InputError
                                                                message={
                                                                    rowError
                                                                }
                                                            />
                                                        </li>
                                                    );
                                                },
                                            )}
                                        </ul>
                                    )}
                                </div>

                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => addRange(day.weekday)}
                                >
                                    <Plus />
                                    Add hours
                                    <span className="sr-only">
                                        {' '}
                                        on {day.name}
                                    </span>
                                </Button>
                            </fieldset>
                        );
                    })}
                </div>

                <InputError message={listError} />

                <p className="text-sm text-muted-foreground">
                    Times are clinic time. Add two ranges for a day with a lunch
                    break, for example 08:00 to 12:00 and 13:00 to 17:00. A day
                    with no range is closed. Bookings that already exist are not
                    changed.
                </p>

                <div className="flex items-center gap-3">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Save working hours
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={DoctorController.index()}>Cancel</Link>
                    </Button>
                    {form.isDirty && (
                        <p
                            role="status"
                            className="text-sm font-medium text-amber-ink"
                        >
                            Unsaved changes
                        </p>
                    )}
                </div>
            </div>

            <WeekSummary ranges={form.data.ranges} days={DAYS} />
        </form>
    );
}
