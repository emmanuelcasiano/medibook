<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Existing rows get 'patient' and active, so this changes no data.
            $table->string('role')->default('patient')->after('password')->index();
            $table->boolean('is_active')->default(true)->after('role');
            // `text`, not `date`: the value is stored encrypted (a long string), set at registration.
            $table->text('birth_date')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'is_active', 'birth_date']);
        });
    }
};
