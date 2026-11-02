<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One note per appointment, enforced by MySQL and not only by SaveVisitNote. A second row for the same appointment
     * is refused, whatever code tries to write it.
     *
     * Existing data: this fails if two notes already share an appointment (it can't have happened through the app,
     * which has only ever written one). On a database that might have such rows, look first with
     * `select appointment_id from visit_notes group by appointment_id having count(*) > 1`, then merge or remove them.
     */
    public function up(): void
    {
        Schema::table('visit_notes', function (Blueprint $table): void {
            $table->unique('appointment_id');
        });
    }

    /**
     * MySQL keeps the foreign key on `appointment_id` on the unique index once it exists (the plain index from
     * `constrained()` is dropped as redundant), and it refuses to drop an index a foreign key needs. So a plain index
     * goes back first, in its own step, and then the unique one can go.
     */
    public function down(): void
    {
        Schema::table('visit_notes', function (Blueprint $table): void {
            $table->index('appointment_id');
        });

        Schema::table('visit_notes', function (Blueprint $table): void {
            $table->dropUnique(['appointment_id']);
        });
    }
};
