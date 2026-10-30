import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AdminDoctorFormValues, AdminDoctorServiceOption } from '@/types';

type Props = {
    doctor: AdminDoctorFormValues;
    services: AdminDoctorServiceOption[];
};

export default function DoctorForm({ doctor, services }: Props) {
    // `useForm` (not `<Form>`) because an empty list of services must still be sent: with no box ticked, a plain
    // HTML form sends nothing, and the server needs to know the admin chose "no services".
    const form = useForm({
        specialty: doctor.specialty,
        bio: doctor.bio ?? '',
        service_ids: doctor.serviceIds,
    });

    function toggleService(serviceId: number, checked: boolean) {
        form.setData(
            'service_ids',
            checked
                ? [...form.data.service_ids, serviceId]
                : form.data.service_ids.filter((id) => id !== serviceId),
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.submit(DoctorController.update(doctor.id));
    }

    // Validation errors for a single service come back as `service_ids.0`, `service_ids.1`, and so on.
    const serviceError =
        form.errors.service_ids ??
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('service_ids.'),
        )?.[1];

    return (
        <form onSubmit={submit} className="grid gap-6">
            {/* Profile fields on the left, the services checklist on the right. They stack on a phone. */}
            <div className="grid items-start gap-6 md:grid-cols-2 md:gap-x-16">
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="specialty">Specialty</Label>
                        <Input
                            id="specialty"
                            name="specialty"
                            required
                            autoFocus
                            maxLength={255}
                            autoComplete="off"
                            value={form.data.specialty}
                            onChange={(event) =>
                                form.setData('specialty', event.target.value)
                            }
                            placeholder="For example, Pediatrics"
                        />
                        <InputError message={form.errors.specialty} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="bio">Short bio</Label>
                        <textarea
                            id="bio"
                            name="bio"
                            rows={4}
                            maxLength={1000}
                            value={form.data.bio}
                            onChange={(event) =>
                                form.setData('bio', event.target.value)
                            }
                            aria-invalid={form.errors.bio ? true : undefined}
                            className="min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        />
                        <p className="text-sm text-muted-foreground">
                            Shown to patients when they pick a doctor.
                        </p>
                        <InputError message={form.errors.bio} />
                    </div>
                </div>

                <fieldset className="grid gap-3">
                    <legend className="text-sm leading-none font-medium">
                        Services offered
                    </legend>
                    <p className="text-sm text-muted-foreground">
                        Patients can book a service only with a doctor who
                        offers it. Taking a service away does not cancel
                        bookings that already exist.
                    </p>

                    {services.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            There are no services yet. Add one on the Services
                            page first.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {services.map((service) => {
                                const inputId = `service-${service.id}`;

                                return (
                                    <li
                                        key={service.id}
                                        className="flex items-center gap-3 px-4 py-3"
                                    >
                                        <Checkbox
                                            id={inputId}
                                            checked={form.data.service_ids.includes(
                                                service.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggleService(
                                                    service.id,
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor={inputId}
                                            className="flex flex-1 flex-wrap items-center gap-x-3 gap-y-1"
                                        >
                                            <span>{service.name}</span>
                                            <span className="font-normal text-muted-foreground">
                                                {service.durationMinutes} min
                                            </span>
                                            {!service.isActive && (
                                                <Badge variant="outline">
                                                    Not bookable
                                                </Badge>
                                            )}
                                        </Label>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    <InputError message={serviceError} />
                </fieldset>
            </div>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Save changes
                </Button>
                <Button asChild variant="ghost">
                    <Link href={DoctorController.index()}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
