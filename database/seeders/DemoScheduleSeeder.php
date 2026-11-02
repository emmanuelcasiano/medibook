<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Booking\BookAppointment;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A busier clinic for demos and for checking the schedule screens: 4 more doctors (8 in all, two of them off on a
 * Monday) and a few bookings per doctor for today and the next days, with statuses that fit the time of day.
 *
 * Safe to run on a database that already has data: `php artisan db:seed --class=DemoScheduleSeeder`. It never deletes
 * or changes a row. Doctors are found by email, and a doctor's day that already has any booking is left alone, so a
 * second run adds nothing and bookings made by hand are kept as they are. Fake data only.
 */
class DemoScheduleSeeder extends Seeder
{
    /** How many clinic days to fill, counting today. */
    private const int DAYS = 3;

    /** Bookings start on this grid, like the slot finder's (`config('medibook.slot_step_minutes')`). */
    private const int STEP_MINUTES = 15;

    public function run(): void
    {
        $this->addDoctors();
        $this->addAppointments();
    }

    private function addDoctors(): void
    {
        // Weekday numbers follow Carbon: 0 = Sunday ... 6 = Saturday. Dr. Reyes is off on Mondays.
        $doctors = [
            [
                'name' => 'Dr. Maria Santos',
                'email' => 'doctor.santos@medibook.test',
                'specialty' => 'Family Medicine',
                'services' => ['general-consultation', 'annual-physical-exam', 'vaccination'],
                'weekdays' => [1, 2, 3, 4, 5],
                'ranges' => [['08:00', '12:00'], ['13:00', '17:00']],
            ],
            [
                'name' => 'Dr. Jose Reyes',
                'email' => 'doctor.reyes@medibook.test',
                'specialty' => 'Internal Medicine',
                'services' => ['general-consultation', 'annual-physical-exam'],
                'weekdays' => [2, 3, 4, 5, 6],
                'ranges' => [['09:00', '12:00'], ['13:00', '18:00']],
            ],
            [
                'name' => 'Dr. Angela Cruz',
                'email' => 'doctor.cruz@medibook.test',
                'specialty' => 'Pediatrics',
                'services' => ['pediatric-consultation', 'vaccination'],
                'weekdays' => [1, 2, 4, 5],
                'ranges' => [['08:00', '12:00'], ['13:00', '16:00']],
            ],
            [
                'name' => 'Dr. Paolo Villanueva',
                'email' => 'doctor.villanueva@medibook.test',
                'specialty' => 'Dermatology',
                'services' => ['skin-consultation'],
                'weekdays' => [1, 3, 5, 6],
                'ranges' => [['10:00', '12:00'], ['13:00', '17:00']],
            ],
        ];

        $serviceIds = Service::query()->pluck('id', 'slug');

        foreach ($doctors as $definition) {
            $user = User::query()->where('email', $definition['email'])->first()
                ?? User::factory()->doctor()->create(['name' => $definition['name'], 'email' => $definition['email']]);

            if ($user->doctor()->exists()) {
                continue;
            }

            $doctor = Doctor::query()->create([
                'user_id' => $user->id,
                'specialty' => $definition['specialty'],
                'bio' => fake()->paragraph(),
            ]);

            $doctor->services()->attach(
                collect($definition['services'])->map(fn (string $slug): int => $serviceIds[$slug])->all(),
            );

            foreach ($definition['weekdays'] as $weekday) {
                foreach ($definition['ranges'] as [$startsAt, $endsAt]) {
                    $doctor->workingHours()->create([
                        'weekday' => $weekday,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ]);
                }
            }
        }
    }

    private function addAppointments(): void
    {
        $now = CarbonImmutable::now();
        $today = CarbonImmutable::now(config('app.clinic_timezone'))->startOfDay();
        $maxUpcoming = BookAppointment::MAX_UPCOMING_PER_PATIENT;

        // The demo patient is left out: that login is for trying the booking flow by hand.
        $patientIds = User::query()
            ->where('role', Role::Patient)
            ->where('is_active', true)
            ->where('email', '!=', 'patient@medibook.test')
            ->get(['id'])
            ->map(fn (User $patient): int => $patient->id)
            ->values()
            ->all();

        // What each patient already has, so the seeded bookings respect the same limits as BookAppointment:
        // at most 3 upcoming, and never two visits at the same time.
        $upcoming = [];
        $busy = [];

        $held = Appointment::query()->active()->get(['patient_id', 'starts_at', 'ends_at']);

        foreach ($held as $appointment) {
            $busy[$appointment->patient_id][] = [$appointment->starts_at, $appointment->ends_at];

            if ($appointment->starts_at > $now) {
                $upcoming[$appointment->patient_id] = ($upcoming[$appointment->patient_id] ?? 0) + 1;
            }
        }

        $doctors = Doctor::query()->with(['services', 'workingHours'])->orderBy('id')->get();

        foreach ($doctors as $doctor) {
            $services = $doctor->services->where('is_active', true)->values();

            if ($services->isEmpty()) {
                continue;
            }

            for ($offset = 0; $offset < self::DAYS; $offset++) {
                $day = $today->addDays($offset);
                $ranges = $doctor->workingHours->where('weekday', $day->dayOfWeek)->sortBy('starts_at')->values();

                if ($ranges->isEmpty()) {
                    continue;
                }

                // A day that already has any booking is left alone: it was seeded before, or somebody booked it by hand.
                $hasBookings = Appointment::query()
                    ->where('doctor_id', $doctor->id)
                    ->whereBetween('starts_at', [$day->utc(), $day->endOfDay()->utc()])
                    ->exists();

                if ($hasBookings) {
                    continue;
                }

                // 4 to 7 bookings, a different number for each doctor and day but the same on every run.
                $missing = 4 + ($doctor->id + $offset) % 4;

                // The bookings that hold a slot, so a new one in this day can't overlap an earlier one.
                $occupied = [];

                foreach ($ranges as $index => $range) {
                    // Share the bookings between the morning and the afternoon, so the day looks lived in.
                    $quota = intdiv($missing, $ranges->count()) + ($index < $missing % $ranges->count() ? 1 : 0);
                    $placed = 0;

                    $rangeEnd = $day->setTimeFromTimeString($range->ends_at);
                    $cursor = $day->setTimeFromTimeString($range->starts_at)
                        ->addMinutes(self::STEP_MINUTES * fake()->numberBetween(0, 3));

                    while ($placed < $quota) {
                        /** @var Service $service */
                        $service = $services->random();

                        $startsAt = $cursor;
                        $endsAt = $startsAt->addMinutes($service->duration_minutes);
                        $blockedUntil = $endsAt->addMinutes($service->buffer_minutes);

                        if ($blockedUntil > $rangeEnd) {
                            break;
                        }

                        $clash = collect($occupied)->first(
                            fn (array $other): bool => $startsAt < $other[1] && $blockedUntil > $other[0],
                        );

                        if ($clash !== null) {
                            $cursor = $this->roundUp($clash[1]);

                            continue;
                        }

                        $status = $this->statusFor($startsAt, $endsAt, $now);
                        $holdsSlot = $status->isActive();
                        $isUpcoming = $holdsSlot && $startsAt > $now;

                        $patientId = $this->pickPatient($patientIds, $upcoming, $busy, $maxUpcoming, $startsAt, $endsAt, $isUpcoming);

                        if ($patientId === null) {
                            $cursor = $blockedUntil;

                            continue;
                        }

                        Appointment::query()->create([
                            'patient_id' => $patientId,
                            'doctor_id' => $doctor->id,
                            'service_id' => $service->id,
                            // Stored in UTC. The hours above are clinic time, so convert here.
                            'starts_at' => $startsAt->utc(),
                            'ends_at' => $endsAt->utc(),
                            'blocked_until' => $blockedUntil->utc(),
                            'status' => $status,
                            'checked_in_at' => in_array($status, [AppointmentStatus::CheckedIn, AppointmentStatus::Completed], true)
                                ? $startsAt->utc()
                                : null,
                            'cancelled_at' => $status === AppointmentStatus::Cancelled ? $now : null,
                            'cancelled_by' => $status === AppointmentStatus::Cancelled ? $patientId : null,
                        ]);

                        if ($holdsSlot) {
                            $occupied[] = [$startsAt, $blockedUntil];
                            $busy[$patientId][] = [$startsAt, $endsAt];

                            if ($isUpcoming) {
                                $upcoming[$patientId] = ($upcoming[$patientId] ?? 0) + 1;
                            }
                        }

                        $placed++;
                        // A gap of 0 to 45 minutes before the next one, so the grid is not a solid wall.
                        $cursor = $blockedUntil->addMinutes(self::STEP_MINUTES * fake()->numberBetween(0, 3));
                    }
                }
            }
        }
    }

    /** What state a booking at this time would be in by now: past visits are finished, the current one is under way. */
    private function statusFor(CarbonImmutable $startsAt, CarbonImmutable $endsAt, CarbonImmutable $now): AppointmentStatus
    {
        $roll = fake()->numberBetween(1, 100);

        if ($endsAt <= $now) {
            return match (true) {
                $roll <= 72 => AppointmentStatus::Completed,
                $roll <= 86 => AppointmentStatus::NoShow,
                default => AppointmentStatus::Cancelled,
            };
        }

        if ($startsAt <= $now) {
            return AppointmentStatus::CheckedIn;
        }

        return $roll <= 92 ? AppointmentStatus::Booked : AppointmentStatus::Cancelled;
    }

    /**
     * A patient with room for another upcoming booking and no visit at that time, or null when there is none.
     *
     * @param  array<int, int>  $patientIds
     * @param  array<int, int>  $upcoming
     * @param  array<int, list<array{0: CarbonImmutable, 1: CarbonImmutable}>>  $busy
     */
    private function pickPatient(
        array $patientIds,
        array $upcoming,
        array $busy,
        int $maxUpcoming,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        bool $isUpcoming,
    ): ?int {
        $eligible = array_values(array_filter(
            $patientIds,
            function (int $id) use ($upcoming, $busy, $maxUpcoming, $startsAt, $endsAt, $isUpcoming): bool {
                if ($isUpcoming && ($upcoming[$id] ?? 0) >= $maxUpcoming) {
                    return false;
                }

                foreach ($busy[$id] ?? [] as [$otherStart, $otherEnd]) {
                    if ($startsAt < $otherEnd && $endsAt > $otherStart) {
                        return false;
                    }
                }

                return true;
            },
        ));

        return $eligible === [] ? null : $eligible[array_rand($eligible)];
    }

    /** The next point on the 15-minute grid at or after this time. */
    private function roundUp(CarbonImmutable $time): CarbonImmutable
    {
        $time = $time->startOfMinute();
        $over = $time->minute % self::STEP_MINUTES;

        return $over === 0 ? $time : $time->addMinutes(self::STEP_MINUTES - $over);
    }
}
