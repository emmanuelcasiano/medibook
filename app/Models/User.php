<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
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

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
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
