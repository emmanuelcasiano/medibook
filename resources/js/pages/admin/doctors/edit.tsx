import { Head, Link } from '@inertiajs/react';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import WorkingHourController from '@/actions/App/Http/Controllers/Admin/WorkingHourController';
import DoctorForm from '@/components/admin/doctor-form';
import Heading from '@/components/heading';
import type { AdminDoctorFormValues, AdminDoctorServiceOption } from '@/types';

type Props = {
    doctor: AdminDoctorFormValues;
    services: AdminDoctorServiceOption[];
};

export default function AdminDoctorsEdit({ doctor, services }: Props) {
    return (
        <>
            <Head title={`Edit ${doctor.name}`} />

            <div className="max-w-4xl space-y-6 p-4">
                <Heading
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
                    page.
                </p>

                {/* Time off (step 3.4) is added to this page next. */}
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
