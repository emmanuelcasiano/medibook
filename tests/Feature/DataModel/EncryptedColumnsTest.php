<?php

declare(strict_types=1);

use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\User;
use App\Models\VisitNote;
use Illuminate\Support\Facades\DB;

// Read the raw column with the query builder, which skips the model casts. If it were plain text,
// the secret would show up here.

it('encrypts intake answers at rest and decrypts them on read', function () {
    $intake = Intake::factory()->create(['answers' => ['reason' => 'Secret chest rash']]);

    $raw = DB::table('intakes')->where('id', $intake->id)->value('answers');

    expect($raw)->not->toContain('Secret chest rash')
        ->and($intake->fresh()->answers)->toBe(['reason' => 'Secret chest rash']);
});

it('encrypts the AI summary at rest and decrypts it on read', function () {
    $summary = IntakeSummary::factory()->create([
        'summary' => ['chief_complaint' => 'Secret chest rash', 'key_points' => []],
    ]);

    $raw = DB::table('intake_summaries')->where('id', $summary->id)->value('summary');

    expect($raw)->not->toContain('Secret chest rash')
        ->and($summary->fresh()->summary['chief_complaint'])->toBe('Secret chest rash');
});

it('encrypts visit notes at rest and decrypts them on read', function () {
    $note = VisitNote::factory()->create(['body' => 'Secret chest rash']);

    $raw = DB::table('visit_notes')->where('id', $note->id)->value('body');

    expect($raw)->not->toContain('Secret chest rash')
        ->and($note->fresh()->body)->toBe('Secret chest rash');
});

it('encrypts the birth date at rest and decrypts it on read', function () {
    $user = User::factory()->patient()->create(['birth_date' => '1990-05-17']);

    $raw = DB::table('users')->where('id', $user->id)->value('birth_date');

    expect($raw)->not->toContain('1990')
        ->and($user->fresh()->birth_date)->toBe('1990-05-17');
});

it('never puts the birth date in serialized user data', function () {
    $user = User::factory()->patient()->create(['birth_date' => '1990-05-17']);

    // Inertia shares auth.user with every page, so toArray()/toJson() must not carry it.
    expect($user->toArray())->not->toHaveKey('birth_date')
        ->and($user->toJson())->not->toContain('1990-05-17');
});
