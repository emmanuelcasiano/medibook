import { Form } from '@inertiajs/react';
import { CheckCheck } from 'lucide-react';
import DoctorAppointmentController from '@/actions/App/Http/Controllers/Doctor/AppointmentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    appointmentUlid: string;
    /** Called after the visit is finished, once the button is gone. */
    onFinished: () => void;
};

/** "Finish visit" for a checked-in appointment. Shown only when `can.complete` is true. */
export default function FinishVisitForm({
    appointmentUlid,
    onFinished,
}: Props) {
    return (
        <Form
            {...DoctorAppointmentController.complete.form(appointmentUlid)}
            options={{ preserveScroll: true }}
            onSuccess={onFinished}
            className="space-y-2"
        >
            {({ errors, processing }) => (
                <>
                    <Button
                        type="submit"
                        size="lg"
                        disabled={processing}
                        data-test="finish-visit-button"
                    >
                        {processing ? <Spinner /> : <CheckCheck aria-hidden />}
                        Finish visit
                    </Button>
                    <div role="alert">
                        <InputError message={errors.appointment} />
                    </div>
                </>
            )}
        </Form>
    );
}
