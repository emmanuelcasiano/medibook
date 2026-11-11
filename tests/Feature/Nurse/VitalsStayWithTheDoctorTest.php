<?php

declare(strict_types=1);

use App\Actions\Intake\GenerateIntakeSummary;
use App\Ai\Agents\IntakeSummarizer;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\User;
use App\Models\VitalsRecord;
use App\Notifications\AppointmentBooked;
use App\Notifications\AppointmentCancelled;
use App\Notifications\AppointmentReminder;
use Carbon\CarbonImmutable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;

// "Not shared" (nurse vitals, part 3): the readings are for the treating doctor and nobody else. One test walks every
// other place that could carry them: the AI prompt, the appointment emails, the patient's pages, the staff and admin
// schedule, the nurse's queue and the admin audit log. Each place has its own detailed tests (VitalsNeverReachAiTest,
// DayScheduleTest, ViewAuditEventsTest); this one is the safety net across all of them. It has no route or Policy of its
// own, so there is no denied-access or validation test here. "Now" is Monday 2 November 2026, 09:00 in Manila.

it('keeps the readings out of the AI prompt, the emails, the patient pages, the schedule, the nurse queue and the audit log', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Manila'));
    config([
        'medibook.ai.enabled' => true,
        'medibook.ai.provider' => 'anthropic',
        'medibook.ai.model' => 'test-model',
        'medibook.ai.daily_token_cap' => 100000,
    ]);

    $doctorUser = userWithRole(Role::Doctor);
    $patient = User::factory()->patient()->create();
    $appointment = Appointment::factory()
        ->for(Doctor::query()->where('user_id', $doctorUser->id)->firstOrFail())
        ->for($patient, 'patient')
        ->checkedIn()
        ->create(['starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Manila')->utc()]);
    $intake = Intake::factory()->for($appointment)->create();
    VitalsRecord::factory()->forAppointment($appointment)->create([
        'readings' => ['systolic' => 187, 'diastolic' => 121, 'pulse' => 143, 'temperature' => 39.4, 'oxygen_saturation' => 91, 'weight' => 83.7],
    ]);

    // What a leak would look like: a reading with a decimal, and the key names only vitals have.
    $leaks = ['39.4', '83.7', 'systolic', 'diastolic', 'oxygen_saturation'];
    $containsNoReading = function (string $where, string $content) use ($leaks): void {
        foreach ($leaks as $leak) {
            expect($content)->not->toContain($leak, "{$where} carries '{$leak}'");
        }
    };

    // The doctor opens the page first, so the audit log has a `vitals.viewed` row to be checked.
    $this->actingAs($doctorUser)->get(route('doctor.appointments.show', $appointment))->assertOk();

    // 1. The AI prompt. The fake answer is a valid one, and any prompt that was not expected fails the test.
    $fields = ['headline' => 'Dry cough', 'timeline' => ['Cough at night'], 'in_english' => null, 'gaps' => [], 'check_with_patient' => []];
    IntakeSummarizer::fake([new StructuredTextResponse($fields, json_encode($fields), new TextUsage(100, 50), new Meta('anthropic', 'test-model'))])
        ->preventStrayPrompts();
    app(GenerateIntakeSummary::class)->handle($intake);
    IntakeSummarizer::assertPrompted(function ($prompt) use ($leaks): bool {
        foreach ($leaks as $leak) {
            if (str_contains((string) $prompt->prompt, $leak)) {
                return false;
            }
        }

        return true;
    });

    // 2. The emails the patient gets.
    foreach ([AppointmentBooked::class, AppointmentCancelled::class, AppointmentReminder::class] as $notification) {
        $containsNoReading($notification, (string) (new $notification($appointment))->toMail($patient)->render());
    }

    // 3. The patient's own pages.
    foreach ([route('my.home'), route('my.appointments.intake', $appointment), route('my.appointments.confirmation', $appointment)] as $url) {
        $containsNoReading($url, (string) $this->actingAs($patient)->get($url)->getContent());
    }

    // 4. The schedule, for staff and for an admin. Staff get the yes/no mark, never a value.
    foreach ([Role::Staff, Role::Admin] as $role) {
        $containsNoReading("the schedule of {$role->value}", (string) $this->actingAs(userWithRole($role))->get(route('staff.home'))->assertOk()->getContent());
    }

    // 5. The nurse's queue.
    $containsNoReading('the nurse queue', (string) $this->actingAs(userWithRole(Role::Nurse))->get(route('nurse.home'))->assertOk()->getContent());

    // 6. The admin audit log, which lists the view row but never what was viewed.
    $this->actingAs(userWithRole(Role::Admin))->get(route('admin.audit-logs.index', ['action' => 'vitals.viewed']))->assertOk();
    $containsNoReading('the audit log', (string) $this->actingAs(userWithRole(Role::Admin))->get(route('admin.audit-logs.index'))->getContent());
});
