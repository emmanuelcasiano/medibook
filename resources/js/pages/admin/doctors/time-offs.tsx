import { Form, Head, Link } from '@inertiajs/react';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import TimeOffController from '@/actions/App/Http/Controllers/Admin/TimeOffController';
import TimeOffForm from '@/components/admin/time-off-form';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatClinicDateTime } from '@/lib/clinic-time';
import type {
    AdminTimeOffDoctor,
    AdminTimeOffListItem,
    Paginated,
} from '@/types';

type Props = {
    doctor: AdminTimeOffDoctor;
    timeOffs: Paginated<AdminTimeOffListItem>;
};

export default function AdminDoctorsTimeOffs({ doctor, timeOffs }: Props) {
    return (
        <>
            <Head title={`Time off of ${doctor.name}`} />

            <div className="max-w-4xl space-y-8 p-4">
                <Heading
                    title={`Time off: ${doctor.name}`}
                    description="Days or hours when this doctor can't see patients, such as a conference or a holiday. These times are removed from the slots patients can book."
                />

                <section className="space-y-4" aria-labelledby="upcoming">
                    <h3 id="upcoming" className="text-base font-medium">
                        Upcoming time off
                    </h3>

                    {timeOffs.data.length === 0 ? (
                        <p className="rounded-xl border p-4 text-sm text-muted-foreground">
                            No time off planned.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    Upcoming time off of {doctor.name}
                                </caption>
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 font-medium"
                                        >
                                            From
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 font-medium"
                                        >
                                            To
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 font-medium"
                                        >
                                            Reason
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 font-medium"
                                        >
                                            Bookings inside
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right font-medium"
                                        >
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {timeOffs.data.map((timeOff) => (
                                        <tr key={timeOff.id}>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                {formatClinicDateTime(
                                                    timeOff.startsAt,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                {formatClinicDateTime(
                                                    timeOff.endsAt,
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {timeOff.reason ?? (
                                                    <span className="text-muted-foreground">
                                                        No reason given
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                {timeOff.bookingsInside > 0 ? (
                                                    <Badge variant="destructive">
                                                        {timeOff.bookingsInside}{' '}
                                                        {timeOff.bookingsInside ===
                                                        1
                                                            ? 'booking'
                                                            : 'bookings'}{' '}
                                                        inside
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        None
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex justify-end">
                                                    <Form
                                                        {...TimeOffController.destroy.form(
                                                            {
                                                                doctor: doctor.id,
                                                                timeOff:
                                                                    timeOff.id,
                                                            },
                                                        )}
                                                        onBefore={() =>
                                                            window.confirm(
                                                                'Remove this time off? Patients will be able to book these times again.',
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
                                                                Remove
                                                                <span className="sr-only">
                                                                    {' '}
                                                                    time off
                                                                    from{' '}
                                                                    {formatClinicDateTime(
                                                                        timeOff.startsAt,
                                                                    )}
                                                                </span>
                                                            </Button>
                                                        )}
                                                    </Form>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {timeOffs.last_page > 1 && (
                        <nav
                            className="flex items-center justify-between text-sm"
                            aria-label="Pagination"
                        >
                            <p className="text-muted-foreground">
                                Showing {timeOffs.from} to {timeOffs.to} of{' '}
                                {timeOffs.total}
                            </p>
                            <div className="flex gap-2">
                                {timeOffs.prev_page_url ? (
                                    <Button asChild variant="outline" size="sm">
                                        <Link
                                            href={timeOffs.prev_page_url}
                                            preserveScroll
                                        >
                                            Previous
                                        </Link>
                                    </Button>
                                ) : null}
                                {timeOffs.next_page_url ? (
                                    <Button asChild variant="outline" size="sm">
                                        <Link
                                            href={timeOffs.next_page_url}
                                            preserveScroll
                                        >
                                            Next
                                        </Link>
                                    </Button>
                                ) : null}
                            </div>
                        </nav>
                    )}
                </section>

                <section className="space-y-4" aria-labelledby="add-time-off">
                    <h3 id="add-time-off" className="text-base font-medium">
                        Add time off
                    </h3>
                    <TimeOffForm doctor={doctor} />
                </section>

                <Button asChild variant="ghost">
                    <Link href={DoctorController.index()}>Back to doctors</Link>
                </Button>
            </div>
        </>
    );
}

AdminDoctorsTimeOffs.layout = {
    breadcrumbs: [
        { title: 'Doctors', href: DoctorController.index() },
        { title: 'Time off', href: DoctorController.index() },
    ],
};
