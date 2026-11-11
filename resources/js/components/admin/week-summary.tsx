type Range = {
    weekday: number;
    starts_at: string;
    ends_at: string;
};

type Props = {
    ranges: Range[];
    /** Monday first. `weekday` is the stored number (0 = Sunday). */
    days: { weekday: number; name: string }[];
};

function toMinutes(time: string): number | null {
    const match = /^(\d{1,2}):(\d{2})/.exec(time);

    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

/**
 * "Week at a glance": one bar per weekday, drawn from the ranges in the form, so it follows the admin's edits before
 * they save. The axis covers 07:00 to 19:00 and grows when a range goes outside that. A half-typed range (no end, or
 * an end before the start) is skipped. The bars are decoration: the rows beside them already say the same in text.
 */
export default function WeekSummary({ ranges, days }: Props) {
    const valid = ranges.flatMap((range) => {
        const start = toMinutes(range.starts_at);
        const end = toMinutes(range.ends_at);

        return start !== null && end !== null && end > start
            ? [{ weekday: range.weekday, start, end }]
            : [];
    });

    const axisStart =
        Math.min(7, ...valid.map((r) => Math.floor(r.start / 60))) * 60;
    const axisEnd =
        Math.max(19, ...valid.map((r) => Math.ceil(r.end / 60))) * 60;
    const span = axisEnd - axisStart;

    const totalMinutes = valid.reduce((sum, r) => sum + (r.end - r.start), 0);
    const totalHours = Math.round((totalMinutes / 60) * 10) / 10;

    const ticks = [
        axisStart,
        (axisStart * 2 + axisEnd) / 3,
        (axisStart + axisEnd * 2) / 3,
        axisEnd,
    ].map((minutes) => String(Math.round(minutes / 60)).padStart(2, '0'));

    return (
        <section
            className="grid content-start gap-4 rounded-xl border bg-card p-5 shadow-xs"
            aria-labelledby="week-at-a-glance"
        >
            <div className="flex items-baseline justify-between gap-3">
                <h2 id="week-at-a-glance" className="text-base font-semibold">
                    Week at a glance
                </h2>
                <p className="font-semibold">{totalHours} h</p>
            </div>

            <div aria-hidden className="grid gap-2">
                <div className="flex justify-between pl-10 font-mono text-[11px] text-muted-foreground">
                    {ticks.map((tick, index) => (
                        <span key={index}>{tick}</span>
                    ))}
                </div>
                {days.map((day) => (
                    <div key={day.weekday} className="flex items-center gap-2">
                        <span className="w-8 shrink-0 text-[13px] text-muted-foreground">
                            {day.name.slice(0, 3)}
                        </span>
                        <div className="relative h-4 flex-1 rounded bg-muted">
                            {valid
                                .filter((r) => r.weekday === day.weekday)
                                .map((r, index) => (
                                    <i
                                        key={index}
                                        className="absolute inset-y-0 rounded bg-primary"
                                        style={{
                                            left: `${((r.start - axisStart) / span) * 100}%`,
                                            width: `${((r.end - r.start) / span) * 100}%`,
                                        }}
                                    />
                                ))}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
