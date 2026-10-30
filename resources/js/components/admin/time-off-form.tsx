import { Form } from '@inertiajs/react';
import TimeOffController from '@/actions/App/Http/Controllers/Admin/TimeOffController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AdminTimeOffDoctor } from '@/types';

type Props = {
    doctor: AdminTimeOffDoctor;
};

export default function TimeOffForm({ doctor }: Props) {
    return (
        <Form
            {...TimeOffController.store.form(doctor.id)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid gap-6"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="starts_at">From</Label>
                            <Input
                                id="starts_at"
                                name="starts_at"
                                type="datetime-local"
                                step={900}
                                required
                                aria-invalid={
                                    errors.starts_at ? true : undefined
                                }
                            />
                            <InputError message={errors.starts_at} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="ends_at">To</Label>
                            <Input
                                id="ends_at"
                                name="ends_at"
                                type="datetime-local"
                                step={900}
                                required
                                aria-invalid={errors.ends_at ? true : undefined}
                            />
                            <InputError message={errors.ends_at} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="reason">Reason (optional)</Label>
                        <Input
                            id="reason"
                            name="reason"
                            maxLength={255}
                            autoComplete="off"
                            placeholder="For example, Conference"
                            aria-invalid={errors.reason ? true : undefined}
                        />
                        <InputError message={errors.reason} />
                    </div>

                    <p className="text-sm text-muted-foreground">
                        Times are clinic time. Patients can't book this doctor
                        between these times. Bookings that already exist are not
                        changed: you will be warned how many there are, and
                        staff move them.
                    </p>

                    <div>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Add time off
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
