import { Form } from '@inertiajs/react';
import { Phone } from 'lucide-react';
import { useId } from 'react';
import FollowUpController from '@/actions/App/Http/Controllers/Staff/FollowUpController';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { callOutcomes } from '@/lib/follow-up-outcomes';
import type { StaffFollowUpPanel } from '@/types';

type Props = {
    followUp: StaffFollowUpPanel;
};

/**
 * "Log a call": one outcome from a fixed list and nothing typed. There is no free-text box on purpose, so no health
 * detail can end up in a record staff can read (it is not encrypted). "Doesn't need the visit" closes the follow-up, so
 * the dialog says so before it is saved. The server checks the outcome and that the follow-up is still open.
 */
export default function LogCallDialog({ followUp }: Props) {
    const outcomeId = useId();

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    data-test="log-call-button"
                >
                    <Phone aria-hidden />
                    Log a call
                </Button>
            </DialogTrigger>

            <DialogContent>
                <Form
                    {...FollowUpController.call.form(followUp.ulid)}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Log a call</DialogTitle>
                                <DialogDescription>
                                    How did the call with {followUp.patientName}{' '}
                                    go? Only the outcome is saved, so no health
                                    details are kept here.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor={outcomeId}>Outcome</Label>
                                <NativeSelect
                                    id={outcomeId}
                                    name="outcome"
                                    defaultValue={callOutcomes[0].value}
                                    aria-invalid={
                                        errors.outcome ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.outcome
                                            ? `${outcomeId}-error`
                                            : undefined
                                    }
                                >
                                    {callOutcomes.map((outcome) => (
                                        <option
                                            key={outcome.value}
                                            value={outcome.value}
                                        >
                                            {outcome.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError
                                    id={`${outcomeId}-error`}
                                    message={errors.outcome}
                                />
                            </div>

                            <div role="alert">
                                {errors.follow_up && (
                                    <AlertError
                                        errors={[errors.follow_up]}
                                        title="The call was not logged."
                                    />
                                )}
                            </div>

                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="confirm-log-call-button"
                                >
                                    {processing && <Spinner />}
                                    Save
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
