import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import IntakeController from '@/actions/App/Http/Controllers/Patient/IntakeController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    { name: 'fever', label: 'I have a fever' },
    { name: 'pregnant', label: 'I am pregnant, or I might be' },
    { name: 'recent_surgery', label: 'I have had surgery recently' },
];

export default function IntakeForm({
    appointmentUlid,
    answers,
    durations,
}: Props) {
    const [checked, setChecked] = useState<Checklist>({
        fever: answers?.fever ?? false,
        pregnant: answers?.pregnant ?? false,
        recent_surgery: answers?.recent_surgery ?? false,
    });

    return (
        <Form
            {...IntakeController.update.form(appointmentUlid)}
            disableWhileProcessing
            className="grid gap-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="reason">Reason for your visit</Label>
                        <Textarea
                            id="reason"
                            name="reason"
                            rows={3}
                            required
                            maxLength={500}
                            defaultValue={answers?.reason ?? ''}
                            aria-invalid={errors.reason ? true : undefined}
                            aria-describedby={
                                errors.reason ? 'reason-error' : undefined
                            }
                        />
                        <InputError id="reason-error" message={errors.reason} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="symptoms">Your symptoms</Label>
                        <Textarea
                            id="symptoms"
                            name="symptoms"
                            rows={4}
                            maxLength={2000}
                            defaultValue={answers?.symptoms ?? ''}
                            aria-invalid={errors.symptoms ? true : undefined}
                            aria-describedby={
                                errors.symptoms ? 'symptoms-error' : undefined
                            }
                        />
                        <InputError
                            id="symptoms-error"
                            message={errors.symptoms}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="duration">
                            How long have you had this?
                        </Label>
                        <NativeSelect
                            id="duration"
                            name="duration"
                            defaultValue={answers?.duration ?? ''}
                            aria-invalid={errors.duration ? true : undefined}
                            aria-describedby={
                                errors.duration ? 'duration-error' : undefined
                            }
                        >
                            <option value="">Choose one</option>
                            {durations.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError
                            id="duration-error"
                            message={errors.duration}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="allergies">Allergies</Label>
                        <Textarea
                            id="allergies"
                            name="allergies"
                            rows={2}
                            maxLength={500}
                            defaultValue={answers?.allergies ?? ''}
                            aria-invalid={errors.allergies ? true : undefined}
                            aria-describedby={
                                errors.allergies ? 'allergies-error' : undefined
                            }
                        />
                        <InputError
                            id="allergies-error"
                            message={errors.allergies}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="medicines">
                            Medicines you take now
                        </Label>
                        <Textarea
                            id="medicines"
                            name="medicines"
                            rows={2}
                            maxLength={500}
                            defaultValue={answers?.medicines ?? ''}
                            aria-invalid={errors.medicines ? true : undefined}
                            aria-describedby={
                                errors.medicines ? 'medicines-error' : undefined
                            }
                        />
                        <InputError
                            id="medicines-error"
                            message={errors.medicines}
                        />
                    </div>

                    <fieldset className="grid gap-3">
                        <legend className="mb-1 text-sm leading-none font-medium">
                            Tick any that apply
                        </legend>
                        {checklist.map(({ name, label }) => (
                            <div key={name} className="grid gap-2">
                                <div className="flex items-center gap-3">
                                    {/* The server needs an explicit 1 or 0: an unchecked box sends nothing. */}
                                    <input
                                        type="hidden"
                                        name={name}
                                        value={checked[name] ? '1' : '0'}
                                    />
                                    <Checkbox
                                        id={name}
                                        checked={checked[name]}
                                        onCheckedChange={(value) =>
                                            setChecked((current) => ({
                                                ...current,
                                                [name]: value === true,
                                            }))
                                        }
                                    />
                                    <Label htmlFor={name}>{label}</Label>
                                </div>
                                <InputError message={errors[name]} />
                            </div>
                        ))}
                    </fieldset>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit">
                            {processing && <Spinner />}
                            Save intake
                        </Button>
                        <Button asChild variant="outline">
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
