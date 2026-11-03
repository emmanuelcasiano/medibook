import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Zap } from 'lucide-react';
import ServiceController from '@/actions/App/Http/Controllers/Booking/ServiceController';
import SlotController from '@/actions/App/Http/Controllers/Booking/SlotController';
import BookingSteps from '@/components/booking/booking-steps';
import DoctorAvatar from '@/components/booking/doctor-avatar';
import PageHeader from '@/components/page-header';
import type { BookableDoctor, BookableService } from '@/types';

type Props = {
    service: BookableService;
    doctors: BookableDoctor[];
};

const optionClass =
    'group flex items-center gap-4 rounded-xl border bg-card p-4 outline-none hover:border-teal-line hover:bg-teal-soft/40 focus-visible:ring-[3px] focus-visible:ring-ring';

export default function BookingDoctors({ service, doctors }: Props) {
    return (
        <>
            <Head title={`Choose a doctor for ${service.name}`} />

            <div className="space-y-6">
                <BookingSteps current={2} />

                <PageHeader
                    title="Who would you like to see?"
                    description={
                        <p>
                            {service.name} · {service.durationMinutes} min{' '}
                            <Link
                                href={ServiceController.index()}
                                className="text-foreground underline underline-offset-4"
                            >
                                Change service
                            </Link>
                        </p>
                    }
                />

                {doctors.length === 0 ? (
                    <p className="rounded-xl border bg-card p-6 text-sm text-muted-foreground">
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
                                <span
                                    aria-hidden
                                    className="flex size-14 shrink-0 items-center justify-center rounded-full bg-deep text-mint"
                                >
                                    <Zap className="size-6" />
                                </span>
                                <span className="min-w-0 flex-1 space-y-1">
                                    <span className="block text-[17px] font-semibold">
                                        Any available doctor
                                    </span>
                                    <span className="block text-sm text-muted-foreground">
                                        Shows every time that at least one
                                        doctor is free.
                                    </span>
                                </span>
                                <ChevronRight
                                    aria-hidden
                                    className="size-5 shrink-0 text-muted-foreground group-hover:text-teal-ink"
                                />
                            </Link>
                        </li>

                        {/* A divider in words, so the first card reads as a different kind of choice. */}
                        <li
                            aria-hidden
                            className="flex items-center gap-3 py-1 text-sm text-muted-foreground"
                        >
                            <span className="h-px flex-1 bg-border" />
                            or choose a doctor
                            <span className="h-px flex-1 bg-border" />
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
                                    <DoctorAvatar name={doctor.name} />
                                    <span className="min-w-0 flex-1 space-y-1">
                                        <span className="block text-[17px] font-semibold">
                                            {doctor.name}
                                        </span>
                                        <span className="block text-sm font-medium text-teal-ink">
                                            {doctor.specialty}
                                        </span>
                                        {doctor.bio && (
                                            <span className="line-clamp-2 text-sm text-muted-foreground">
                                                {doctor.bio}
                                            </span>
                                        )}
                                    </span>
                                    <ChevronRight
                                        aria-hidden
                                        className="size-5 shrink-0 text-muted-foreground group-hover:text-teal-ink"
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
