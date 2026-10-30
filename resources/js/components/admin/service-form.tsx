import { Form, Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { useState } from 'react';
import ServiceController from '@/actions/App/Http/Controllers/Admin/ServiceController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AdminServiceFormValues } from '@/types';

type Props = {
    formAttributes: Pick<ComponentProps<typeof Form>, 'action' | 'method'>;
    service?: AdminServiceFormValues;
    submitLabel: string;
};

export default function ServiceForm({
    formAttributes,
    service,
    submitLabel,
}: Props) {
    const [isActive, setIsActive] = useState(service?.isActive ?? true);

    return (
        <Form {...formAttributes} disableWhileProcessing className="grid gap-6">
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            required
                            autoFocus
                            maxLength={255}
                            autoComplete="off"
                            defaultValue={service?.name}
                            placeholder="For example, General consultation"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="description">Description</Label>
                        <textarea
                            id="description"
                            name="description"
                            rows={3}
                            maxLength={1000}
                            defaultValue={service?.description ?? ''}
                            aria-invalid={errors.description ? true : undefined}
                            className="min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-6 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="duration_minutes">
                                Length (minutes)
                            </Label>
                            <Input
                                id="duration_minutes"
                                name="duration_minutes"
                                type="number"
                                inputMode="numeric"
                                required
                                min={1}
                                max={480}
                                step={1}
                                defaultValue={service?.durationMinutes ?? 30}
                            />
                            <p className="text-sm text-muted-foreground">
                                How long the visit takes. It sets the slot size.
                            </p>
                            <InputError message={errors.duration_minutes} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="buffer_minutes">
                                Buffer after (minutes)
                            </Label>
                            <Input
                                id="buffer_minutes"
                                name="buffer_minutes"
                                type="number"
                                inputMode="numeric"
                                required
                                min={0}
                                max={120}
                                step={1}
                                defaultValue={service?.bufferMinutes ?? 0}
                            />
                            <p className="text-sm text-muted-foreground">
                                A gap kept free after each visit.
                            </p>
                            <InputError message={errors.buffer_minutes} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-center gap-3">
                            {/* The server needs an explicit 1 or 0: an unchecked box sends nothing. */}
                            <input
                                type="hidden"
                                name="is_active"
                                value={isActive ? '1' : '0'}
                            />
                            <Checkbox
                                id="is_active"
                                checked={isActive}
                                onCheckedChange={(checked) =>
                                    setIsActive(checked === true)
                                }
                            />
                            <Label htmlFor="is_active">Bookable</Label>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Patients can only book a service that is bookable.
                            Turn it off to hide a service without deleting it.
                        </p>
                        <InputError message={errors.is_active} />
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit">
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                        <Button asChild variant="ghost">
                            <Link href={ServiceController.index()}>Cancel</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
