import { Head, Link } from '@inertiajs/react';
import { Stethoscope } from 'lucide-react';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import TimeOffController from '@/actions/App/Http/Controllers/Admin/TimeOffController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import WorkingHourController from '@/actions/App/Http/Controllers/Admin/WorkingHourController';
import StatePill from '@/components/admin/state-pill';
import DoctorAvatar from '@/components/booking/doctor-avatar';
import { ActionsTd, DataTable, Pager, Td, Th } from '@/components/data-table';
import EmptyState from '@/components/empty-state';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import type { AdminDoctorListItem, Paginated } from '@/types';

type Props = {
    doctors: Paginated<AdminDoctorListItem>;
};

export default function AdminDoctorsIndex({ doctors }: Props) {
    return (
        <>
            <Head title="Doctors" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Doctors"
                    description="Set each doctor's specialty, bio, services, working hours and time off. A new doctor is added on the Users page."
                />

                {doctors.data.length === 0 ? (
                    <EmptyState
                        icon={Stethoscope}
                        action={
                            <Button asChild variant="outline">
                                <Link href={UserController.create()}>
                                    Add a user
                                </Link>
                            </Button>
                        }
                    >
                        No doctors yet. Add a user with the Doctor role first.
                    </EmptyState>
                ) : (
                    <DataTable caption="All doctors of the clinic">
                        <thead>
                            <tr>
                                <Th>Doctor</Th>
                                <Th>Specialty</Th>
                                <Th>Services</Th>
                                <Th srOnly>Actions</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {doctors.data.map((doctor) => (
                                <tr key={doctor.id}>
                                    <Td>
                                        <div className="flex items-center gap-3">
                                            <DoctorAvatar
                                                name={doctor.name}
                                                className="size-10 text-sm"
                                            />
                                            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <span className="font-medium">
                                                    {doctor.name}
                                                </span>
                                                {!doctor.isActive && (
                                                    <StatePill tone="warning">
                                                        Account inactive
                                                    </StatePill>
                                                )}
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>{doctor.specialty}</Td>
                                    <Td className="whitespace-nowrap">
                                        {doctor.servicesCount === 0 ? (
                                            <span className="text-muted-foreground">
                                                None yet
                                            </span>
                                        ) : (
                                            doctor.servicesCount
                                        )}
                                    </Td>
                                    <ActionsTd>
                                        {doctor.can.update && (
                                            <>
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={WorkingHourController.edit(
                                                            doctor.id,
                                                        )}
                                                    >
                                                        Working hours
                                                        <span className="sr-only">
                                                            {' '}
                                                            of {doctor.name}
                                                        </span>
                                                    </Link>
                                                </Button>
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={TimeOffController.index(
                                                            doctor.id,
                                                        )}
                                                    >
                                                        Time off
                                                        <span className="sr-only">
                                                            {' '}
                                                            of {doctor.name}
                                                        </span>
                                                    </Link>
                                                </Button>
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={DoctorController.edit(
                                                            doctor.id,
                                                        )}
                                                    >
                                                        Edit
                                                        <span className="sr-only">
                                                            {' '}
                                                            {doctor.name}
                                                        </span>
                                                    </Link>
                                                </Button>
                                            </>
                                        )}
                                    </ActionsTd>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}

                <Pager paginator={doctors} empty="" />
            </div>
        </>
    );
}

AdminDoctorsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Doctors',
            href: DoctorController.index(),
        },
    ],
};
