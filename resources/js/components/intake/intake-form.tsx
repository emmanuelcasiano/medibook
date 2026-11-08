import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import IntakeController from '@/actions/App/Http/Controllers/Patient/IntakeController';
import InputError from '@/components/input-error';
import IntakeSection from '@/components/intake/intake-section';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { home as myHome } from '@/routes/my';
import type { IntakeAnswers, IntakeDurationOption } from '@/types';

type Props = {
    appointmentUlid: string;
    /** The saved answers, or null before the first save. */
    answers: IntakeAnswers | null;
    durations: IntakeDurationOption[];
};

type Checklist = Pick<IntakeAnswers, 'fever' | 'pregnant' | 'recent_surgery'>;

const checklist: { name: keyof Checklist; label: string }[] = [
    { name: 'fever', label: 'Do you have a fever?' },
    { name: 'pregnant', label: 'Are you pregnant, or might you be?' },
    { name: 'recent_surgery', label: 'Have you had surgery recently?' },
];

export default function IntakeForm({
    appointmentUlid,
    answers,
    durations,
}: Props) {
    return (
        <Form
            {...IntakeController.update.form(appointmentUlid)}
            disableWhileProcessing
            className="grid gap-6"
        >
            {({ processing, errors }) => (
                <>
                    <IntakeSection title="About this visit">
                        <TextField
                            id="reason"
                            label="Reason for your visit"
                            required
                            rows={3}
                            maxLength={500}
                            defaultValue={answers?.reason ?? ''}
                            error={errors.reason}
                        />
                    </IntakeSection>

                    <IntakeSection title="Symptoms">
                        <TextField
                            id="symptoms"
                            label="Your symptoms"
                            rows={4}
                            maxLength={2000}
                            defaultValue={answers?.symptoms ?? ''}
                            error={errors.symptoms}
                        />

                        <div className="grid gap-2 sm:max-w-xs">
                            <Label htmlFor="duration">
                                How long have you had this?
                            </Label>
                            <NativeSelect
                                id="duration"
                                name="duration"
                                defaultValue={answers?.duration ?? ''}
                                aria-invalid={
                                    errors.duration ? true : undefined
                                }
                                aria-describedby={
                                    errors.duration
                                        ? 'duration-error'
                                        : undefined
                                }
                            >
                                <option value="">Choose one</option>
                                {durations.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError
                                id="duration-error"
                                message={errors.duration}
                            />
                        </div>
                    </IntakeSection>

                    <IntakeSection title="Health background">
                        <TextField
                            id="allergies"
                            label="Allergies"
                            rows={2}
                            maxLength={500}
                            defaultValue={answers?.allergies ?? ''}
                            error={errors.allergies}
                        />
                        <TextField
                            id="medicines"
                            label="Medicines you take now"
                            rows={2}
                            maxLength={500}
                            defaultValue={answers?.medicines ?? ''}
                            error={errors.medicines}
                        />

                        <div className="grid gap-4">
                            {checklist.map(({ name, label }) => (
                                <YesNo
                                    key={name}
                                    name={name}
                                    label={label}
                                    defaultValue={answers?.[name] ?? false}
                                    error={errors[name]}
                                />
                            ))}
                        </div>
                    </IntakeSection>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" size="lg">
                            {processing && <Spinner />}
                            Save intake
                        </Button>
                        <Button asChild variant="outline" size="lg">
                            <Link href={myHome()}>Back</Link>
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            You can edit this until you check in.
                        </span>
                    </div>
                </>
            )}
        </Form>
    );
}

type TextFieldProps = {
    id: string;
    label: string;
    rows: number;
    maxLength: number;
    defaultValue: string;
    required?: boolean;
    error?: string;
};

/** A label, a textarea, the error beside it and a character counter. The limit is the one the server enforces. */
function TextField({
    id,
    label,
    rows,
    maxLength,
    defaultValue,
    required = false,
    error,
}: TextFieldProps) {
    const [length, setLength] = useState(defaultValue.length);
    const errorId = `${id}-error`;
    const counterId = `${id}-counter`;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>
                {label}
                {required && (
                    <>
                        {' '}
                        <span className="font-normal text-muted-foreground">
                            (required)
                        </span>
                    </>
                )}
            </Label>
            <Textarea
                id={id}
                name={id}
                rows={rows}
                required={required}
                maxLength={maxLength}
                defaultValue={defaultValue}
                onChange={(event) => setLength(event.target.value.length)}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${counterId} ${errorId}` : counterId}
            />
            <div className="flex items-start justify-between gap-3">
                <InputError id={errorId} message={error} />
                <span
                    id={counterId}
                    className="ml-auto text-xs text-muted-foreground tabular-nums"
                >
                    {length.toLocaleString('en-US')} /{' '}
                    {maxLength.toLocaleString('en-US')}
                </span>
            </div>
        </div>
    );
}

type YesNoProps = {
    name: keyof Checklist;
    label: string;
    defaultValue: boolean;
    error?: string;
};

/**
 * A Yes/No question as two large segments. They are real radio buttons, so the keyboard (arrow keys) and screen
 * readers work as they do for any radio group. The form always sends an explicit 1 or 0, as `SaveIntakeRequest`
 * requires; a new intake starts on "No", as the old checkboxes did.
 */
function YesNo({ name, label, defaultValue, error }: YesNoProps) {
    const labelId = `${name}-label`;
    const errorId = `${name}-error`;

    return (
        <div className="grid gap-2">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <span id={labelId} className="text-sm font-medium">
                    {label}
                </span>
                <div
                    role="radiogroup"
                    aria-labelledby={labelId}
                    aria-describedby={error ? errorId : undefined}
                    className="inline-flex gap-1 rounded-lg border bg-muted p-1"
                >
                    {[
                        { value: '1', text: 'Yes', checked: defaultValue },
                        { value: '0', text: 'No', checked: !defaultValue },
                    ].map(({ value, text, checked }) => (
                        <label
                            key={value}
                            className="relative flex h-11 min-w-20 cursor-pointer items-center justify-center rounded-md px-5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground has-checked:bg-primary has-checked:text-primary-foreground has-checked:shadow-xs has-focus-visible:ring-[3px] has-focus-visible:ring-ring"
                        >
                            <input
                                type="radio"
                                name={name}
                                value={value}
                                defaultChecked={checked}
                                className="sr-only"
                            />
                            {text}
                        </label>
                    ))}
                </div>
            </div>
            <InputError id={errorId} message={error} />
        </div>
    );
}
