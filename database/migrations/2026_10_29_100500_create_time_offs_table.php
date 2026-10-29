<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_offs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            // UTC. `dateTime`, not `timestamp`, so there is no 2038 limit and no session-timezone conversion.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_offs');
    }
};
