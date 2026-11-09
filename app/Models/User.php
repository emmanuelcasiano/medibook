<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Role $role
 * @property bool $is_active
 * @property string|null $birth_date Decrypted 'Y-m-d' string. Never shown; only the age in years is.
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// `role` and `is_active` are not fillable on purpose: they are set explicitly by Actions (CreateStaffUser,
// registration), so a request can never promote a user by adding `role=admin` to a form.
#[Fillable(['name', 'email', 'password', 'birth_date'])]
// `birth_date` is hidden because Inertia shares `auth.user` with every page, and it must never reach the browser.
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'birth_date'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => Role::class,
            'is_active' => 'boolean',
            'birth_date' => 'encrypted',
        ];
    }

    public function isPatient(): bool
    {
        return $this->role === Role::Patient;
    }

    public function isStaff(): bool
    {
        return $this->role === Role::Staff;
    }

    public function isDoctor(): bool
    {
        return $this->role === Role::Doctor;
    }

    public function isNurse(): bool
    {
        return $this->role === Role::Nurse;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * The first word of the name, for pages that may not show the surname (the doctor's views).
     * `squish` turns any run of whitespace (tab, non-breaking space, ...) into one plain space first, so the surname
     * can't slip through behind a separator that `before(' ')` would not see.
     */
    public function firstName(): string
    {
        return (string) Str::of($this->name)->squish()->before(' ');
    }

    /**
     * Whole years since the birth date, counted on the clinic calendar (Asia/Manila), or null when there is no birth
     * date (only patients have one). This is the only form of the birth date that may leave the server: the date
     * itself is never shown. The caller must have loaded `birth_date` (strict mode throws if it was not selected).
     */
    public function ageInYears(): ?int
    {
        if ($this->birth_date === null) {
            return null;
        }

        $timezone = (string) config('app.clinic_timezone');

        // A birth date in the future (bad data) is 0, not a negative age.
        return max(0, (int) CarbonImmutable::parse($this->birth_date, $timezone)->diffInYears(CarbonImmutable::now($timezone)));
    }

    /** True when this role must have 2FA on, and it is not on yet. */
    public function needsTwoFactorSetup(): bool
    {
        return $this->role->requiresTwoFactor() && ! $this->hasEnabledTwoFactorAuthentication();
    }

    /**
     * The id of this user's doctor profile, or null for non-doctors.
     * Policies compare ids instead of reading the `doctor` relation, because lazy loading throws in strict mode.
     */
    public function doctorId(): ?int
    {
        return once(fn (): ?int => $this->isDoctor()
            ? Doctor::query()->where('user_id', $this->id)->value('id')
            : null);
    }

    /**
     * The doctor profile, only for users with the doctor role.
     *
     * @return HasOne<Doctor, $this>
     */
    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    /**
     * Appointments this user booked as a patient.
     *
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'patient_id');
    }
}
