import { Form, Head, Link, usePage } from '@inertiajs/react';
import ServiceController from '@/actions/App/Http/Controllers/Admin/ServiceController';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
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
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title="Services"
                        description="Length sets the slot size. The buffer is the gap kept free after each visit."
                    />
                    <Button asChild>
                        <Link href={ServiceController.create()}>
                            Add service
                        </Link>
                    </Button>
                </div>

                {/* Shown when a delete is refused, for example because the service has appointments. */}
                <div role="alert">
                    {errors.service && (
                        <AlertError
                            errors={[errors.service]}
                            title="That service was not deleted."
                        />
                    )}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <caption className="sr-only">
                            All services of the clinic
                        </caption>
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Service
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Length
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Buffer
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Doctors
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Bookable
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 text-right font-medium"
                                >
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {services.data.map((service) => (
                                <tr key={service.id}>
                                    <td className="px-4 py-3">
                                        <div className="font-medium">
                                            {service.name}
                                        </div>
                                        {service.description && (
                                            <div className="text-muted-foreground">
                                                {service.description}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {service.durationMinutes} min
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {service.bufferMinutes} min
                                    </td>
                                    <td className="px-4 py-3">
                                        {service.doctorNames.length > 0
                                            ? service.doctorNames.join(', ')
                                            : 'None yet'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge
                                            variant={
                                                service.isActive
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {service.isActive ? 'Yes' : 'No'}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex justify-end gap-2">
                                            {service.can.update && (
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    size="sm"
                                                >
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
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
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
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <nav
                    className="flex items-center justify-between text-sm"
                    aria-label="Pagination"
                >
                    <p className="text-muted-foreground">
                        {services.from !== null
                            ? `Showing ${services.from} to ${services.to} of ${services.total}`
                            : 'No services yet'}
                    </p>
                    <div className="flex gap-2">
                        {services.prev_page_url ? (
                            <Button asChild variant="outline" size="sm">
                                <Link
                                    href={services.prev_page_url}
                                    preserveScroll
                                >
                                    Previous
                                </Link>
                            </Button>
                        ) : null}
                        {services.next_page_url ? (
                            <Button asChild variant="outline" size="sm">
                                <Link
                                    href={services.next_page_url}
                                    preserveScroll
                                >
                                    Next
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                </nav>

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
