<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table): void {
            $table->id();
            // restrictOnDelete: users are deactivated, never hard-deleted, so a delete must not silently remove a doctor.
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('specialty');
            $table->text('bio')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
