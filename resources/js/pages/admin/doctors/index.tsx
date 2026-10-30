import { Head, Link } from '@inertiajs/react';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import TimeOffController from '@/actions/App/Http/Controllers/Admin/TimeOffController';
import WorkingHourController from '@/actions/App/Http/Controllers/Admin/WorkingHourController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
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
                <Heading
                    title="Doctors"
                    description="Set each doctor's specialty, bio, services, working hours and time off. A new doctor is added on the Users page."
                />

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <caption className="sr-only">
                            All doctors of the clinic
                        </caption>
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Doctor
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Specialty
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Services
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
                            {doctors.data.map((doctor) => (
                                <tr key={doctor.id}>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">
                                                {doctor.name}
                                            </span>
                                            {!doctor.isActive && (
                                                <Badge variant="outline">
                                                    Account inactive
                                                </Badge>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {doctor.specialty}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {doctor.servicesCount === 0
                                            ? 'None yet'
                                            : doctor.servicesCount}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex justify-end gap-2">
                                            {doctor.can.update && (
                                                <>
                                                    <Button
                                                        asChild
                                                        variant="outline"
                                                        size="sm"
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
                                                        size="sm"
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
                                                        size="sm"
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
                        {doctors.from !== null
                            ? `Showing ${doctors.from} to ${doctors.to} of ${doctors.total}`
                            : 'No doctors yet'}
                    </p>
                    <div className="flex gap-2">
                        {doctors.prev_page_url ? (
                            <Button asChild variant="outline" size="sm">
                                <Link
                                    href={doctors.prev_page_url}
                                    preserveScroll
                                >
                                    Previous
                                </Link>
                            </Button>
                        ) : null}
                        {doctors.next_page_url ? (
                            <Button asChild variant="outline" size="sm">
                                <Link
                                    href={doctors.next_page_url}
                                    preserveScroll
                                >
                                    Next
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                </nav>
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
