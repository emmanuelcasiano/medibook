import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import ServiceController from '@/actions/App/Http/Controllers/Booking/ServiceController';
import SlotController from '@/actions/App/Http/Controllers/Booking/SlotController';
import BookingSteps from '@/components/booking/booking-steps';
import type { BookableDoctor, BookableService } from '@/types';

type Props = {
    service: BookableService;
    doctors: BookableDoctor[];
};

const optionClass =
    'flex items-center justify-between gap-3 rounded-xl border bg-card p-4 outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function BookingDoctors({ service, doctors }: Props) {
    return (
        <>
            <Head title={`Choose a doctor for ${service.name}`} />

            <div className="space-y-6">
                <BookingSteps current={2} />

                <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Who would you like to see?
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {service.name} · {service.durationMinutes} min{' '}
                        <Link
                            href={ServiceController.index()}
                            className="text-foreground underline underline-offset-4"
                        >
                            Change service
                        </Link>
                    </p>
                </div>

                {doctors.length === 0 ? (
                    <p className="rounded-xl border p-6 text-sm text-muted-foreground">
                        No doctor offers this service right now. Please pick
                        another service.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        <li>
                            <Link
                                href={SlotController.any(service)}
                                className={optionClass}
                            >
                                <span className="space-y-1">
                                    <span className="block font-medium">
                                        Any available doctor
                                    </span>
                                    <span className="block text-sm text-muted-foreground">
                                        Shows every time that at least one
                                        doctor is free.
                                    </span>
                                </span>
                                <ChevronRight
                                    aria-hidden
                                    className="size-5 shrink-0 text-muted-foreground"
                                />
                            </Link>
                        </li>
                        {doctors.map((doctor) => (
                            <li key={doctor.id}>
                                <Link
                                    href={SlotController.doctor({
                                        service,
                                        doctor,
                                    })}
                                    className={optionClass}
                                >
                                    <span className="space-y-1">
                                        <span className="block font-medium">
                                            {doctor.name}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {doctor.specialty}
                                        </span>
                                        {doctor.bio && (
                                            <span className="block text-sm text-muted-foreground">
                                                {doctor.bio}
                                            </span>
                                        )}
                                    </span>
                                    <ChevronRight
                                        aria-hidden
                                        className="size-5 shrink-0 text-muted-foreground"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
