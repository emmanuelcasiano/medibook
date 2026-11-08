<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table): void {
            $table->id();
            // Public id used in URLs, so nobody can guess /follow-ups/1, /2, /3.
            $table->ulid('ulid')->unique();

            // restrictOnDelete on every FK: a follow-up is a record of what a doctor asked, never removed by a cascade.
            $table->foreignId('patient_id')->constrained('users')->restrictOnDelete();
            // The doctor who asked, even when `same_doctor` is false.
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            // The finished visit that led to this request, and the booking made from it (set in L.3/L.4).
            $table->foreignId('source_appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->foreignId('booked_appointment_id')->nullable()->constrained('appointments')->restrictOnDelete();

            // Clinic-local calendar days (Asia/Manila), not UTC instants: the doctor picks days, not times.
            $table->date('window_starts_on');
            $table->date('window_ends_on');
            // true = only `doctor_id`, false = any doctor who offers the service.
            $table->boolean('same_doctor')->default(true);

            // Free text from the doctor, can hold health details: ciphertext via the `encrypted` cast.
            $table->text('note')->nullable();

            $table->string('status')->default('open');
            $table->dateTime('last_reminded_at')->nullable();

            $table->timestamps();

            // "My appointments": a patient's open follow-ups.
            $table->index(['patient_id', 'status']);
            // Staff list: open ones by the day the window ends.
            $table->index(['status', 'window_ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
