<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            // A mark and nothing more: it says the nurse took vitals, and carries no value. Staff may read it, so it
            // must never hold a reading. It is not an AppointmentStatus.
            $table->dateTime('vitals_taken_at')->nullable()->after('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('vitals_taken_at');
        });
    }
};
