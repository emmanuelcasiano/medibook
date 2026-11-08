<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\FollowUpStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\Service;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates an open follow-up with a future window by default', function () {
    $followUp = FollowUp::factory()->create()->fresh();

    expect($followUp->status)->toBe(FollowUpStatus::Open)
        ->and($followUp->same_doctor)->toBeTrue()
        ->and($followUp->booked_appointment_id)->toBeNull()
        ->and($followUp->last_reminded_at)->toBeNull()
        ->and($followUp->window_starts_on->isFuture())->toBeTrue()
        ->and($followUp->window_ends_on->greaterThanOrEqualTo($followUp->window_starts_on))->toBeTrue();
});

it('takes the patient and doctor from the finished visit', function () {
    $followUp = FollowUp::factory()->create();

    expect($followUp->patient_id)->toBe($followUp->sourceAppointment->patient_id)
        ->and($followUp->doctor_id)->toBe($followUp->sourceAppointment->doctor_id)
        ->and($followUp->sourceAppointment->status)->toBe(AppointmentStatus::Completed);
});

it('resolves its relations', function () {
    $source = Appointment::factory()->completed()->create();
    $service = Service::factory()->create();

    $followUp = FollowUp::factory()->create([
        'source_appointment_id' => $source->id,
        'service_id' => $service->id,
    ])->fresh();

    expect($followUp->patient->is($source->patient))->toBeTrue()
        ->and($followUp->doctor->is($source->doctor))->toBeTrue()
        ->and($followUp->service->is($service))->toBeTrue()
        ->and($followUp->sourceAppointment->is($source))->toBeTrue()
        ->and($followUp->bookedAppointment)->toBeNull();
});

it('creates a booked follow-up that points at a booking for the same patient and doctor', function () {
    $followUp = FollowUp::factory()->booked()->create();

    expect($followUp->status)->toBe(FollowUpStatus::Booked)
        ->and($followUp->bookedAppointment)->not->toBeNull()
        ->and($followUp->bookedAppointment->patient_id)->toBe($followUp->patient_id)
        ->and($followUp->bookedAppointment->doctor_id)->toBe($followUp->doctor_id)
        ->and($followUp->bookedAppointment->is($followUp->sourceAppointment))->toBeFalse();
});

it('creates follow-ups in the other states', function () {
    $dismissed = FollowUp::factory()->dismissed()->create();
    $ended = FollowUp::factory()->windowEnded()->create();
    $anyDoctor = FollowUp::factory()->anyDoctor()->create();

    expect($dismissed->status)->toBe(FollowUpStatus::Dismissed)
        ->and($ended->status)->toBe(FollowUpStatus::Open)
        ->and($ended->window_ends_on->isPast())->toBeTrue()
        ->and($anyDoctor->same_doctor)->toBeFalse();
});

it('stores the window as plain clinic-local days with no time or timezone shift', function () {
    $followUp = FollowUp::factory()->create([
        'window_starts_on' => '2026-11-12',
        'window_ends_on' => '2026-11-16',
    ]);

    // Raw column, read with the query builder, which skips the casts.
    $raw = DB::table('follow_ups')->where('id', $followUp->id)->first();

    expect($raw->window_starts_on)->toBe('2026-11-12')
        ->and($raw->window_ends_on)->toBe('2026-11-16')
        ->and($followUp->fresh()->window_starts_on->toDateString())->toBe('2026-11-12')
        ->and($followUp->fresh()->window_ends_on->toDateString())->toBe('2026-11-16');
});

it('has the indexes the patient and staff lists need', function () {
    $indexes = collect(Schema::getIndexes('follow_ups'))->pluck('columns', 'name');

    expect($indexes->values()->all())->toContain(['patient_id', 'status'])
        ->and($indexes->values()->all())->toContain(['status', 'window_ends_on'])
        ->and($indexes->values()->all())->toContain(['ulid']);
});

it('fills a ULID and routes by it, not by the numeric id', function () {
    $followUp = FollowUp::factory()->create();

    expect($followUp->ulid)->toHaveLength(26)
        ->and($followUp->getRouteKeyName())->toBe('ulid')
        ->and($followUp->getRouteKey())->toBe($followUp->ulid)
        ->and(new FollowUp()->resolveRouteBinding($followUp->ulid)?->is($followUp))->toBeTrue();
});

it('does not resolve a follow-up from its numeric id', function () {
    $followUp = FollowUp::factory()->create();

    // A guessed /follow-ups/1 must be a 404, so IDs can't be counted up.
    expect(fn () => new FollowUp()->resolveRouteBinding((string) $followUp->id))
        ->toThrow(ModelNotFoundException::class);
});

it('gives each status a label', function (FollowUpStatus $status, string $label) {
    expect($status->label())->toBe($label);
})->with([
    [FollowUpStatus::Open, 'Open'],
    [FollowUpStatus::Booked, 'Booked'],
    [FollowUpStatus::Dismissed, 'Dismissed'],
]);

// restrictOnDelete: the database refuses to remove a record a follow-up still points to.
it('refuses to delete a record that a follow-up points to', function (string $relation) {
    $followUp = FollowUp::factory()->booked()->create();

    expect(fn () => $followUp->{$relation}->delete())->toThrow(QueryException::class);
})->with([
    'patient' => 'patient',
    'doctor' => 'doctor',
    'service' => 'service',
    'source appointment' => 'sourceAppointment',
    'booked appointment' => 'bookedAppointment',
]);

it('leaves the visit and the doctor alone when the follow-up itself is deleted', function () {
    // The restrict rules only guard the other direction: removing the follow-up is always allowed.
    $followUp = FollowUp::factory()->create();
    $source = $followUp->sourceAppointment;
    $doctor = Doctor::query()->findOrFail($followUp->doctor_id);

    $followUp->delete();

    expect($source->fresh())->not->toBeNull()
        ->and($doctor->fresh())->not->toBeNull();
});
