<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vitals_records', function (Blueprint $table): void {
            $table->id();
            // unique: one record per appointment. A correction edits the row instead of adding a second one, and the
            // index is the last line of defence if two saves ever pass the application check together.
            $table->foreignId('appointment_id')->unique()->constrained()->restrictOnDelete();
            // The nurse who took the readings. A user with vitals on record can't be deleted (users are only deactivated).
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            // Health data. The model casts it with `encrypted:array`, so this holds ciphertext (long, not queryable).
            $table->longText('readings');
            $table->dateTime('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vitals_records');
    }
};
