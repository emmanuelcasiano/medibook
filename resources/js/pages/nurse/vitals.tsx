import { Form, Head, Link } from '@inertiajs/react';
import { Save } from 'lucide-react';
import VitalsController from '@/actions/App/Http/Controllers/Nurse/VitalsController';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicDateTime } from '@/lib/clinic-time';
import { home as nurseHome } from '@/routes/nurse';
import type { VitalsFormAppointment, VitalsReadings } from '@/types';

type Props = {
    appointment: VitalsFormAppointment;
    /** null until the nurse saves the first readings. */
    readings: VitalsReadings | null;
};

type Field = {
    name: keyof VitalsReadings;
    label: string;
    unit: string;
    /** `decimal` shows a number keypad with a point on a phone. */
    inputMode: 'numeric' | 'decimal';
};

/** The order the nurse takes them in. The units are fixed, so a nurse can't enter pounds by mistake. */
const fields: Field[] = [
    {
        name: 'systolic',
        label: 'Systolic pressure (upper number)',
        unit: 'mmHg',
        inputMode: 'numeric',
    },
    {
        name: 'diastolic',
        label: 'Diastolic pressure (lower number)',
        unit: 'mmHg',
        inputMode: 'numeric',
    },
    {
        name: 'pulse',
        label: 'Pulse',
        unit: 'beats per minute',
        inputMode: 'numeric',
    },
    {
        name: 'temperature',
        label: 'Temperature',
        unit: '°C, one decimal',
        inputMode: 'decimal',
    },
    {
        name: 'oxygen_saturation',
        label: 'Oxygen saturation',
        unit: '%',
        inputMode: 'numeric',
    },
    {
        name: 'weight',
        label: 'Weight',
        unit: 'kg, one decimal',
        inputMode: 'decimal',
    },
];

export default function NurseVitals({ appointment, readings }: Props) {
    const editing = readings !== null;

    return (
        <>
            <Head title={editing ? 'Edit vitals' : 'Record vitals'} />

            <div className="space-y-6 p-4">
                <div className="max-w-2xl space-y-6">
                    <PageHeader
                        title={editing ? 'Edit vitals' : 'Record vitals'}
                        description="The doctor reads these before the visit starts."
                    />

                    <section
                        aria-label="The patient"
                        className="space-y-1 rounded-xl border bg-card p-4"
                    >
                        <p className="text-base font-semibold">
                            {appointment.patientName}
                        </p>
                        <p className="font-mono text-sm tabular-nums">
                            {formatClinicDateTime(appointment.startsAt)}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {appointment.serviceName} · {appointment.doctorName}
                        </p>
                    </section>

                    <p className="text-sm text-muted-foreground">
                        Demo with fake data. Do not enter real health
                        information.
                    </p>

                    {/* No `key`: the fields keep what the nurse typed if the server sends a message back. */}
                    <Form
                        {...VitalsController.update.form(appointment.ulid)}
                        disableWhileProcessing
                        className="grid gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-5 sm:grid-cols-2">
                                    {fields.map((field) => (
                                        <ReadingField
                                            key={field.name}
                                            field={field}
                                            defaultValue={
                                                readings?.[field.name]
                                            }
                                            error={errors[field.name]}
                                        />
                                    ))}
                                </div>

                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="submit"
                                        size="lg"
                                        data-test="save-vitals-button"
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <Save aria-hidden />
                                        )}
                                        Save vitals
                                    </Button>
                                    <Button asChild variant="outline" size="lg">
                                        <Link href={nurseHome()}>Back</Link>
                                    </Button>
                                    <span className="text-sm text-muted-foreground">
                                        You can correct these until the doctor
                                        finishes the visit.
                                    </span>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

/**
 * One reading: a label, the unit, and the error, all tied to the input with `aria-describedby`, so a screen reader
 * says "Pulse, beats per minute, Check the reading". It is a text field with a keypad hint and not a number field: a
 * number field changes its value on a mouse wheel scroll, and the server does the checking.
 */
function ReadingField({
    field,
    defaultValue,
    error,
}: {
    field: Field;
    defaultValue: number | undefined;
    error: string | undefined;
}) {
    const id = `vitals-${field.name}`;

    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{field.label}</Label>
            <div className="flex items-center gap-2">
                <Input
                    id={id}
                    name={field.name}
                    type="text"
                    inputMode={field.inputMode}
                    autoComplete="off"
                    required
                    defaultValue={defaultValue ?? ''}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={
                        error ? `${id}-unit ${id}-error` : `${id}-unit`
                    }
                    className="max-w-32 font-mono tabular-nums"
                />
                <span
                    id={`${id}-unit`}
                    className="text-sm text-muted-foreground"
                >
                    {field.unit}
                </span>
            </div>
            <div id={`${id}-error`} role="alert">
                <InputError message={error} />
            </div>
        </div>
    );
}

NurseVitals.layout = {
    breadcrumbs: [
        {
            title: 'Triage',
            href: nurseHome(),
        },
        {
            title: 'Vitals',
            href: nurseHome(),
        },
    ],
};
