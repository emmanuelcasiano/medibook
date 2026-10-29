<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status')->default('pending')->index();
            // AI output about a patient's health: encrypted like the intake itself.
            $table->longText('summary')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            // Exception class name only, never a message, because messages can carry intake text.
            $table->string('error')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_summaries');
    }
};
