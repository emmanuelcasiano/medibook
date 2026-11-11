import { Head, Link } from '@inertiajs/react';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import TimeOffController from '@/actions/App/Http/Controllers/Admin/TimeOffController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import WorkingHourController from '@/actions/App/Http/Controllers/Admin/WorkingHourController';
import DoctorForm from '@/components/admin/doctor-form';
import PageHeader from '@/components/page-header';
import type { AdminDoctorFormValues, AdminDoctorServiceOption } from '@/types';

type Props = {
    doctor: AdminDoctorFormValues;
    services: AdminDoctorServiceOption[];
    /** The doctor's user id, or null when the Policy would refuse the user edit screen. */
    userId: number | null;
};

export default function AdminDoctorsEdit({ doctor, services, userId }: Props) {
    return (
        <>
            <Head title={`Edit ${doctor.name}`} />

            <div className="max-w-4xl space-y-6 p-4">
                <PageHeader
                    title={doctor.name}
                    description="Change the specialty, the short bio and the services this doctor offers. Name and email are edited with the user account."
                />

                <DoctorForm
                    // A new key resets the form fields if the admin opens another doctor.
                    key={doctor.id}
                    doctor={doctor}
                    services={services}
                />

                <p className="text-sm text-muted-foreground">
                    The times this doctor sees patients are set on the{' '}
                    <Link
                        href={WorkingHourController.edit(doctor.id)}
                        className="font-medium underline underline-offset-4"
                    >
                        working hours
                    </Link>{' '}
                    page. Days off are added on the{' '}
                    <Link
                        href={TimeOffController.index(doctor.id)}
                        className="font-medium underline underline-offset-4"
                    >
                        time off
                    </Link>{' '}
                    page.
                    {userId !== null && (
                        <>
                            {' '}
                            The name and email are changed on the{' '}
                            <Link
                                href={UserController.edit(userId)}
                                className="font-medium underline underline-offset-4"
                            >
                                user account
                            </Link>{' '}
                            page.
                        </>
                    )}
                </p>
            </div>
        </>
    );
}

AdminDoctorsEdit.layout = {
    breadcrumbs: [
        { title: 'Doctors', href: DoctorController.index() },
        { title: 'Edit doctor', href: DoctorController.index() },
    ],
};
