<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('working_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            // 0 = Sunday ... 6 = Saturday (Carbon's dayOfWeek). Local clinic time, not UTC.
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            // The slot finder reads all ranges of one doctor on one weekday.
            $table->index(['doctor_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_hours');
    }
};
