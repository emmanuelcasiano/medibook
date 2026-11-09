<?php

declare(strict_types=1);

use App\Actions\Staff\CreatePatientAccount;
use App\Actions\Staff\NewPatient;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

// The patient account staff make at the desk (the walk-in dialog). The Form Request checks the facts; these tests cover
// what the Action itself guarantees: who may call it, that nobody knows the password, that the account starts
// unverified, what is audited, and that the unique index on `users.email` is the last word on a duplicate.

beforeEach(function () {
    Notification::fake();
    $this->staff = userWithRole(Role::Staff);
    $this->input = new NewPatient('Ana Reyes', 'ana.reyes@example.test', '1991-04-12');
});

it('makes an active, unverified patient with the facts staff typed', function () {
    $patient = app(CreatePatientAccount::class)->handle($this->staff, $this->input);

    expect($patient->role)->toBe(Role::Patient)
        ->and($patient->is_active)->toBeTrue()
        ->and($patient->name)->toBe('Ana Reyes')
        ->and($patient->email)->toBe('ana.reyes@example.test')
        ->and($patient->birth_date)->toBe('1991-04-12')
        ->and($patient->email_verified_at)->toBeNull();
});

it('gives the account a password nobody knows, so only the emailed link can open it', function () {
    $first = app(CreatePatientAccount::class)->handle($this->staff, $this->input);
    $second = app(CreatePatientAccount::class)->handle($this->staff, new NewPatient('Ben Cruz', 'ben.cruz@example.test', '1985-01-30'));

    // Hashed (not stored as typed), different for every account, and not any password a person would use.
    expect($first->password)->not->toBe($second->password)
        ->and(Hash::check('password', $first->password))->toBeFalse()
        ->and(Hash::check('', $first->password))->toBeFalse()
        ->and(Hash::info($first->password)['algoName'])->not->toBe('unknown');
});

it('emails the patient the verification link and a link to choose a password', function () {
    $patient = app(CreatePatientAccount::class)->handle($this->staff, $this->input);

    Notification::assertSentToTimes($patient, VerifyEmail::class, 1);
    Notification::assertSentToTimes($patient, ResetPassword::class, 1);
});

it('audits who made it and the role only, never the email or the birth date', function () {
    $patient = app(CreatePatientAccount::class)->handle($this->staff, $this->input);

    $log = AuditLog::query()->where('action', 'user.created')->sole();

    expect($log->actor_id)->toBe($this->staff->id)
        ->and($log->subject_id)->toBe($patient->id)
        ->and($log->metadata)->toBe(['role' => 'patient']);
});

it('refuses everyone but staff before it writes anything', function (Role $role) {
    $actor = userWithRole($role);
    $accountsBefore = User::query()->count();

    expect(fn () => app(CreatePatientAccount::class)->handle($actor, $this->input))
        ->toThrow(AuthorizationException::class);

    expect(User::query()->count())->toBe($accountsBefore)
        ->and(AuditLog::query()->count())->toBe(0);

    Notification::assertNothingSent();
})->with([
    'patient' => [Role::Patient],
    'doctor' => [Role::Doctor],
    'nurse' => [Role::Nurse],
    'admin' => [Role::Admin],
]);

it('is stopped by the unique index when two desks take the same email at once, with the form\'s own message and nothing sent', function () {
    // The Form Request checks the email first. This is the last line of defence when both requests passed that check.
    User::factory()->patient()->create(['email' => 'ana.reyes@example.test']);

    try {
        app(CreatePatientAccount::class)->handle($this->staff, $this->input);
        $errors = [];
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect($errors)->toBe(['new_patient.email' => ['This email address already has an account. Search for the patient instead.']])
        ->and(User::query()->where('email', 'ana.reyes@example.test')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'user.created')->count())->toBe(0);

    Notification::assertNothingSent();
});

it('keeps the account when an email fails to send, and logs the id and the error class but never the address', function () {
    Log::spy();
    // A mail error can quote the address it could not reach.
    Password::shouldReceive('sendResetLink')->once()->andThrow(new RuntimeException('Cannot reach ana.reyes@example.test'));

    $patient = app(CreatePatientAccount::class)->handle($this->staff, $this->input);

    expect(User::query()->whereKey($patient->id)->exists())->toBeTrue();

    Log::shouldHaveReceived('warning')->once()->with('Patient account emails were not sent', [
        'user_id' => $patient->id,
        'exception' => RuntimeException::class,
    ]);
});
