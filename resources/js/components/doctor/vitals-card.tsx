import { HeartPulse } from 'lucide-react';
import type { ReactNode } from 'react';
import { formatClinicDateTime } from '@/lib/clinic-time';
import type { DoctorVitals } from '@/types';

type Props = {
    /** null when the nurse has not recorded vitals yet. */
    vitals: DoctorVitals | null;
};

/**
 * The nurse's readings, read-only. The doctor never edits them here, and the values are plain numbers with their
 * units: no "high" or "low" label and no color, because the app does not judge a reading.
 */
export default function VitalsCard({ vitals }: Props) {
    return (
        <section
            aria-labelledby="vitals-heading"
            className="space-y-4 rounded-xl border bg-card p-5"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="vitals-heading" className="text-lg font-semibold">
                    Vitals
                </h2>
                {vitals !== null && (
                    <span className="text-sm text-muted-foreground">
                        Recorded by {vitals.recordedByName} ·{' '}
                        {formatClinicDateTime(vitals.recordedAt)}
                    </span>
                )}
            </div>

            {vitals === null ? (
                <div className="flex flex-col items-center gap-2 rounded-lg bg-muted/50 p-6 text-center">
                    <HeartPulse
                        aria-hidden
                        className="size-6 text-muted-foreground"
                    />
                    <p className="font-medium">Not recorded yet.</p>
                </div>
            ) : (
                <dl
                    className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5"
                    data-test="vitals-readings"
                >
                    <Reading label="Blood pressure">
                        {vitals.readings.systolic}/{vitals.readings.diastolic}{' '}
                        <Unit>mmHg</Unit>
                    </Reading>
                    <Reading label="Pulse">
                        {vitals.readings.pulse} <Unit>beats/min</Unit>
                    </Reading>
                    <Reading label="Temperature">
                        {vitals.readings.temperature.toFixed(1)} <Unit>°C</Unit>
                    </Reading>
                    <Reading label="Oxygen saturation">
                        {vitals.readings.oxygen_saturation} <Unit>%</Unit>
                    </Reading>
                    <Reading label="Weight">
                        {vitals.readings.weight.toFixed(1)} <Unit>kg</Unit>
                    </Reading>
                </dl>
            )}
        </section>
    );
}

/** One label and its reading. */
function Reading({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1">
            <dt className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="text-lg font-semibold tabular-nums">{children}</dd>
        </div>
    );
}

function Unit({ children }: { children: ReactNode }) {
    return (
        <span className="text-sm font-normal text-muted-foreground">
            {children}
        </span>
    );
}
