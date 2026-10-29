<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;

it('knows which statuses hold a slot', function (AppointmentStatus $status, bool $active) {
    expect($status->isActive())->toBe($active)
        ->and(in_array($status, AppointmentStatus::active(), true))->toBe($active);
})->with([
    'booked' => [AppointmentStatus::Booked, true],
    'checked in' => [AppointmentStatus::CheckedIn, true],
    'completed' => [AppointmentStatus::Completed, true],
    'cancelled' => [AppointmentStatus::Cancelled, false],
    'no-show' => [AppointmentStatus::NoShow, false],
]);

it('lists exactly three active statuses', function () {
    expect(AppointmentStatus::active())->toHaveCount(3);
});

it('has a readable label for every status', function (AppointmentStatus $status, string $label) {
    expect($status->label())->toBe($label);
})->with([
    [AppointmentStatus::Booked, 'Booked'],
    [AppointmentStatus::CheckedIn, 'Checked in'],
    [AppointmentStatus::Completed, 'Completed'],
    [AppointmentStatus::Cancelled, 'Cancelled'],
    [AppointmentStatus::NoShow, 'No-show'],
]);
