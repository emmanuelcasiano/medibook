<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreatePatientAccount
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Make a patient account for someone at the front desk (the walk-in dialog). Staff never choose or learn a
     * password: the account gets a random one nobody knows, and the patient is emailed the usual verification link and
     * a link to set their own password. Until they do, the account cannot be signed in to, and the email address is
     * not trusted (the account starts unverified, unlike one an admin makes).
     *
     * The caller does not validate the email here. The Form Request checks that it is free, and the unique index on
     * `users.email` stops two requests from taking it at once: the loser gets the same validation error as the Form
     * Request would have given.
     *
     * Checks the Policy itself (architecture.md section 3), so a caller that skips the controller can't skip the check.
     *
     * The two emails go out after the outermost transaction commits (`DB::afterCommit`). BookWalkIn calls this inside
     * its own transaction, so a refused booking rolls the account back and sends nothing to a stranger's inbox. An email
     * that fails to send is logged (ids only), not thrown: the account and the booking are already saved, and "Forgot
     * password" on the login page gets the patient the same link.
     *
     * `user.created` is written in the same transaction: the role only, never the email or the birth date.
     *
     * @throws AuthorizationException When $actor may not create a patient (only staff may).
     * @throws ValidationException When the email address was taken since the Form Request checked it.
     */
    public function handle(User $actor, NewPatient $input): User
    {
        Gate::forUser($actor)->authorize('createPatient', User::class);

        $patient = DB::transaction(function () use ($actor, $input): User {
            $user = new User([
                'name' => $input->name,
                'email' => $input->email,
                // The `hashed` cast hashes it. 64 random characters: nobody, staff included, can type or guess it.
                'password' => Str::random(64),
                'birth_date' => $input->birthDate,
            ]);

            // `role` and `is_active` are not fillable, so they are set here and only here. No `email_verified_at`.
            try {
                $user->forceFill(['role' => Role::Patient, 'is_active' => true])->save();
            } catch (UniqueConstraintViolationException) {
                // Two desks took the same address at once: the Form Request saw it free, the unique index did not. The
                // same message as the Form Request gives, so the dialog shows it next to the field and not a server error.
                throw ValidationException::withMessages(['new_patient.email' => 'This email address already has an account. Search for the patient instead.']);
            }

            $this->audit->record($actor, 'user.created', $user, ['role' => Role::Patient->value]);

            return $user;
        });

        DB::afterCommit(function () use ($patient): void {
            try {
                $patient->sendEmailVerificationNotification();
                Password::sendResetLink(['email' => $patient->email]);
            } catch (Throwable $e) {
                // The id and the class only: a mail error can quote the recipient's address, and logs hold no personal text.
                Log::warning('Patient account emails were not sent', ['user_id' => $patient->id, 'exception' => $e::class]);
            }
        });

        return $patient;
    }
}
