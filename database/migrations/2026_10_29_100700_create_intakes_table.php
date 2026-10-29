<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intakes', function (Blueprint $table): void {
            $table->id();
            // unique: one intake per appointment. The form edits it until check-in instead of adding a second row.
            $table->foreignId('appointment_id')->unique()->constrained()->restrictOnDelete();
            // Health text. The model casts it with `encrypted:array`, so this holds ciphertext (long, not queryable).
            $table->longText('answers');
            $table->dateTime('submitted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intakes');
    }
};
