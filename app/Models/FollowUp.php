<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FollowUpStatus;
use Carbon\CarbonImmutable;
use Database\Factories\FollowUpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A doctor's request that a patient comes back inside a date range.
 *
 * @property int $id
 * @property string $ulid
 * @property int $patient_id
 * @property int $doctor_id The doctor who asked, even when `same_doctor` is false.
 * @property int $service_id
 * @property int $source_appointment_id The finished visit that led to this request.
 * @property int|null $booked_appointment_id Set when the follow-up is booked, cleared if that booking is cancelled.
 * @property CarbonImmutable $window_starts_on Clinic-local day (Asia/Manila), no time part.
 * @property CarbonImmutable $window_ends_on Clinic-local day (Asia/Manila), no time part.
 * @property bool $same_doctor True: only `doctor_id`. False: any doctor who offers the service.
 * @property string|null $note The doctor's note to the patient, encrypted at rest. Can hold health details: never log it, never show it to staff, never put it in email.
 * @property FollowUpStatus $status
 * @property CarbonImmutable|null $last_reminded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $patient The four `*_id` keys above are NOT NULL foreign keys, so these never come back null.
 * @property-read Doctor $doctor
 * @property-read Service $service
 * @property-read Appointment $sourceAppointment
 * @property-read Appointment|null $bookedAppointment
 */
// `status` and `booked_appointment_id` are fillable because Actions call create()/update() with them.
// A Form Request must never accept them from user input.
#[Fillable([
    'patient_id', 'doctor_id', 'service_id',
    'source_appointment_id', 'booked_appointment_id',
    'window_starts_on', 'window_ends_on', 'same_doctor',
    'note', 'status', 'last_reminded_at',
])]
// `note` is health text: keep it out of toArray()/JSON, so a model passed to Inertia or a log can't leak it.
#[Hidden(['note'])]
// Private records are addressed by ULID, so IDs in URLs can't be counted up. A Policy is still the real protection.
#[RouteKey('ulid')]
class FollowUp extends Model
{
    /** @use HasFactory<FollowUpFactory> */
    use HasFactory, HasUlids;

    /**
     * HasUlids fills the primary key by default. Our primary key is the normal auto-increment `id`,
     * so point it at the `ulid` column instead.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'window_starts_on' => 'immutable_date',
            'window_ends_on' => 'immutable_date',
            'same_doctor' => 'boolean',
            'note' => 'encrypted',
            'status' => FollowUpStatus::class,
            'last_reminded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether $instant falls on a day of the window. The window is in clinic days, so the instant is turned into its
     * clinic date first, and the dates are compared as 'Y-m-d' text so no timezone can shift a day.
     */
    public function windowIncludes(CarbonImmutable $instant): bool
    {
        $day = $instant->setTimezone((string) config('app.clinic_timezone'))->toDateString();

        return $day >= $this->window_starts_on->toDateString() && $day <= $this->window_ends_on->toDateString();
    }

    /**
     * The doctor this follow-up must be booked with, when it names one and that doctor can still take this service.
     * Null means "Any doctor" (`same_doctor` is false) or a doctor who can't be booked now: check `same_doctor` to
     * tell them apart. It goes through the service's own list, so a doctor taken off the service, or whose account is
     * off, is never returned. The `service` relation must be loaded first (strict mode).
     */
    public function bookableDoctor(): ?Doctor
    {
        return $this->same_doctor
            ? $this->service->doctors()->bookable()->with('user')->find($this->doctor_id)
            : null;
    }

    /** The window in words for an error message: "9 November and 13 November". */
    public function windowLabel(): string
    {
        return $this->window_starts_on->format('j F').' and '.$this->window_ends_on->format('j F');
    }

    /** @return BelongsTo<User, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Appointment, $this> */
    public function sourceAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'source_appointment_id');
    }

    /** @return BelongsTo<Appointment, $this> */
    public function bookedAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'booked_appointment_id');
    }
}
