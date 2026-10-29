<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_service', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            // One row per pair. The database refuses a doctor offering the same service twice.
            $table->unique(['doctor_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_service');
    }
};
