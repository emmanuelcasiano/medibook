import { Form, Head, Link, usePage } from '@inertiajs/react';
import ServiceController from '@/actions/App/Http/Controllers/Admin/ServiceController';
import StatePill from '@/components/admin/state-pill';
import AlertError from '@/components/alert-error';
import { ActionsTd, DataTable, Pager, Td, Th } from '@/components/data-table';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import type { AdminServiceListItem, Paginated } from '@/types';

type Props = {
    services: Paginated<AdminServiceListItem>;
};

export default function AdminServicesIndex({ services }: Props) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Services" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Services"
                    description="Length sets the slot size. The buffer is the gap kept free after each visit."
                    actions={
                        <Button asChild>
                            <Link href={ServiceController.create()}>
                                Add service
                            </Link>
                        </Button>
                    }
                />

                {/* Shown when a delete is refused, for example because the service has appointments. */}
                <div role="alert">
                    {errors.service && (
                        <AlertError
                            errors={[errors.service]}
                            title="That service was not deleted."
                        />
                    )}
                </div>

                <DataTable caption="All services of the clinic">
                    <thead>
                        <tr>
                            <Th>Service</Th>
                            <Th>Length</Th>
                            <Th>Buffer</Th>
                            <Th>Doctors</Th>
                            <Th>Status</Th>
                            <Th srOnly>Actions</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {services.data.map((service) => (
                            <tr key={service.id}>
                                <Td>
                                    <div className="font-medium">
                                        {service.name}
                                    </div>
                                    {service.description && (
                                        <div className="text-muted-foreground">
                                            {service.description}
                                        </div>
                                    )}
                                </Td>
                                <Td className="whitespace-nowrap">
                                    {service.durationMinutes} min
                                </Td>
                                <Td className="whitespace-nowrap">
                                    {service.bufferMinutes} min
                                </Td>
                                <Td>
                                    {service.doctorNames.length > 0 ? (
                                        service.doctorNames.join(', ')
                                    ) : (
                                        <span className="text-muted-foreground">
                                            None yet
                                        </span>
                                    )}
                                </Td>
                                <Td>
                                    <StatePill
                                        tone={
                                            service.isActive
                                                ? 'good'
                                                : 'neutral'
                                        }
                                    >
                                        {service.isActive
                                            ? 'Bookable'
                                            : 'Not bookable'}
                                    </StatePill>
                                </Td>
                                <ActionsTd>
                                    {service.can.update && (
                                        <Button asChild variant="outline">
                                            <Link
                                                href={ServiceController.edit(
                                                    service.id,
                                                )}
                                            >
                                                Edit
                                                <span className="sr-only">
                                                    {' '}
                                                    {service.name}
                                                </span>
                                            </Link>
                                        </Button>
                                    )}
                                    {service.can.delete && (
                                        <Form
                                            {...ServiceController.destroy.form(
                                                service.id,
                                            )}
                                            onBefore={() =>
                                                window.confirm(
                                                    `Delete ${service.name}? Doctors will no longer offer it. This cannot be undone.`,
                                                )
                                            }
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="destructive-outline"
                                                    disabled={processing}
                                                >
                                                    Delete
                                                    <span className="sr-only">
                                                        {' '}
                                                        {service.name}
                                                    </span>
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </ActionsTd>
                            </tr>
                        ))}
                    </tbody>
                </DataTable>

                <Pager paginator={services} empty="No services yet" />

                <p className="text-sm text-muted-foreground">
                    A service with appointments can&apos;t be deleted, because
                    past visits still point to it. Turn off &quot;Bookable&quot;
                    instead.
                </p>
            </div>
        </>
    );
}

AdminServicesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Services',
            href: ServiceController.index(),
        },
    ],
};
