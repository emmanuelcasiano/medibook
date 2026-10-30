import { Head } from '@inertiajs/react';
import ServiceController from '@/actions/App/Http/Controllers/Admin/ServiceController';
import ServiceForm from '@/components/admin/service-form';
import Heading from '@/components/heading';

export default function AdminServicesCreate() {
    return (
        <>
            <Head title="Add service" />

            <div className="max-w-xl space-y-6 p-4">
                <Heading
                    title="Add service"
                    description="Length sets the slot size. The buffer is the gap kept free after each visit."
                />

                <ServiceForm
                    formAttributes={ServiceController.store.form()}
                    submitLabel="Add service"
                />
            </div>
        </>
    );
}

AdminServicesCreate.layout = {
    breadcrumbs: [
        { title: 'Services', href: ServiceController.index() },
        { title: 'Add service', href: ServiceController.create() },
    ],
};
