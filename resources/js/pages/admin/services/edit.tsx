import { Head } from '@inertiajs/react';
import ServiceController from '@/actions/App/Http/Controllers/Admin/ServiceController';
import ServiceForm from '@/components/admin/service-form';
import Heading from '@/components/heading';
import type { AdminServiceFormValues } from '@/types';

type Props = {
    service: AdminServiceFormValues;
};

export default function AdminServicesEdit({ service }: Props) {
    return (
        <>
            <Head title={`Edit ${service.name}`} />

            <div className="max-w-xl space-y-6 p-4">
                <Heading
                    title="Edit service"
                    description="A new length or buffer applies to bookings made from now on. Existing bookings keep their times."
                />

                <ServiceForm
                    // A new key resets the form fields if the admin opens another service.
                    key={service.id}
                    formAttributes={ServiceController.update.form(service.id)}
                    service={service}
                    submitLabel="Save changes"
                />
            </div>
        </>
    );
}

AdminServicesEdit.layout = {
    breadcrumbs: [
        { title: 'Services', href: ServiceController.index() },
        { title: 'Edit service', href: ServiceController.index() },
    ],
};
