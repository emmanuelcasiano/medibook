import { Form, Head } from '@inertiajs/react';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import TimeOffController from '@/actions/App/Http/Controllers/Admin/TimeOffController';
import StatePill from '@/components/admin/state-pill';
import TimeOffForm from '@/components/admin/time-off-form';
import { ActionsTd, DataTable, Pager, Td, Th } from '@/components/data-table';
import PageHeader from '@/components/page-header';
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
                <PageHeader
                    title={`Time off: ${doctor.name}`}
                    description="Days or hours when this doctor can't see patients, such as a conference or a holiday. These times are removed from the slots patients can book."
                />

                <section className="space-y-4" aria-labelledby="upcoming">
                    <h2 id="upcoming" className="text-base font-semibold">
                        Upcoming time off
                    </h2>

                    {timeOffs.data.length === 0 ? (
                        <p className="rounded-xl border bg-card p-4 text-sm text-muted-foreground">
                            No time off planned.
                        </p>
                    ) : (
                        <DataTable
                            caption={`Upcoming time off of ${doctor.name}`}
                        >
                            <thead>
                                <tr>
                                    <Th>From</Th>
                                    <Th>To</Th>
                                    <Th>Reason</Th>
                                    <Th>Bookings inside</Th>
                                    <Th srOnly>Actions</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {timeOffs.data.map((timeOff) => (
                                    <tr key={timeOff.id}>
                                        <Td className="whitespace-nowrap">
                                            {formatClinicDateTime(
                                                timeOff.startsAt,
                                            )}
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {formatClinicDateTime(
                                                timeOff.endsAt,
                                            )}
                                        </Td>
                                        <Td>
                                            {timeOff.reason ?? (
                                                <span className="text-muted-foreground">
                                                    No reason given
                                                </span>
                                            )}
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {timeOff.bookingsInside > 0 ? (
                                                <StatePill tone="warning">
                                                    {timeOff.bookingsInside}{' '}
                                                    {timeOff.bookingsInside ===
                                                    1
                                                        ? 'booking'
                                                        : 'bookings'}{' '}
                                                    inside
                                                </StatePill>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    None
                                                </span>
                                            )}
                                        </Td>
                                        <ActionsTd>
                                            <Form
                                                {...TimeOffController.destroy.form(
                                                    {
                                                        doctor: doctor.id,
                                                        timeOff: timeOff.id,
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
                                                        disabled={processing}
                                                    >
                                                        Remove
                                                        <span className="sr-only">
                                                            {' '}
                                                            time off from{' '}
                                                            {formatClinicDateTime(
                                                                timeOff.startsAt,
                                                            )}
                                                        </span>
                                                    </Button>
                                                )}
                                            </Form>
                                        </ActionsTd>
                                    </tr>
                                ))}
                            </tbody>
                        </DataTable>
                    )}

                    {timeOffs.last_page > 1 && (
                        <Pager paginator={timeOffs} empty="" />
                    )}
                </section>

                <section className="space-y-4" aria-labelledby="add-time-off">
                    <h2 id="add-time-off" className="text-base font-semibold">
                        Add time off
                    </h2>
                    <TimeOffForm doctor={doctor} />
                </section>
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
