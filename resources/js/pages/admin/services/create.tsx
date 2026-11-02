import { Head } from '@inertiajs/react';
import ServiceController from '@/actions/App/Http/Controllers/Admin/ServiceController';
import ServiceForm from '@/components/admin/service-form';
import PageHeader from '@/components/page-header';

export default function AdminServicesCreate() {
    return (
        <>
            <Head title="Add service" />

            <div className="max-w-xl space-y-6 p-4">
                <PageHeader
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
