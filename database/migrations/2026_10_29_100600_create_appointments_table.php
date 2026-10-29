<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            // Public id used in URLs, so nobody can guess /appointments/1, /2, /3.
            $table->ulid('ulid')->unique();

            // restrictOnDelete on all people and service FKs: a booking is a record, never removed by a cascade.
            $table->foreignId('patient_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();

            // All UTC. `blocked_until` = ends_at + the service buffer.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('blocked_until');

            $table->string('status')->default('booked')->index();

            // Guard 3 against double booking (PROJECT.md F4): 1 for active statuses, NULL otherwise.
            // MySQL allows many NULLs in a unique index, so cancelled and no-show rows don't block the slot.
            // Keep the list in sync with AppointmentStatus::active().
            $table->unsignedTinyInteger('active_key')
                ->nullable()
                ->storedAs("CASE WHEN status IN ('booked','checked_in','completed') THEN 1 ELSE NULL END");

            $table->boolean('emergency_flag')->default(false);

            $table->dateTime('cancelled_at')->nullable();
            // nullOnDelete would UPDATE the booking if a user were deleted. Users are never hard-deleted, so restrict.
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();

            $table->timestamps();

            // Also serves as the (doctor_id, starts_at) index for the overlap check, because the
            // first two columns of a composite index are usable on their own.
            $table->unique(['doctor_id', 'starts_at', 'active_key']);
            $table->index(['patient_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
