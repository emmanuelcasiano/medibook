<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_events', function (Blueprint $table): void {
            $table->id();

            // restrictOnDelete on both keys: the history of who chased a patient is never removed by a cascade.
            $table->foreignId('follow_up_id')->constrained()->restrictOnDelete();
            // The staff member who logged the call or sent the reminder.
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();

            // 'called' or 'reminded'. A string column, not a MySQL ENUM, so a case can be added without a schema change.
            $table->string('type');
            // For a call only: one of a fixed list. There is no free-text column on purpose, so no health detail can be
            // typed into a staff-visible record (staff must not read health data, PROJECT.md section 3).
            $table->string('outcome')->nullable();

            // Append-only: an event is never edited, so there is no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // The panel's timeline: one follow-up's events, newest first.
            $table->index(['follow_up_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_events');
    }
};
