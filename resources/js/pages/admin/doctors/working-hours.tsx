import { Head } from '@inertiajs/react';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import WorkingHoursForm from '@/components/admin/working-hours-form';
import Heading from '@/components/heading';
import type { AdminWorkingHoursDoctor, AdminWorkingHourRange } from '@/types';

type Props = {
    doctor: AdminWorkingHoursDoctor;
    ranges: AdminWorkingHourRange[];
};

export default function AdminDoctorsWorkingHours({ doctor, ranges }: Props) {
    return (
        <>
            <Head title={`Working hours of ${doctor.name}`} />

            <div className="max-w-4xl space-y-6 p-4">
                <Heading
                    title={`Working hours: ${doctor.name}`}
                    description="Set the times this doctor sees patients on each weekday. Patients can book only inside these times."
                />

                <WorkingHoursForm
                    // A new key resets the rows after a save (the server sends them sorted) or for another doctor.
                    key={`${doctor.id}:${JSON.stringify(ranges)}`}
                    doctor={doctor}
                    ranges={ranges}
                />
            </div>
        </>
    );
}

AdminDoctorsWorkingHours.layout = {
    breadcrumbs: [
        { title: 'Doctors', href: DoctorController.index() },
        { title: 'Working hours', href: DoctorController.index() },
    ],
};
