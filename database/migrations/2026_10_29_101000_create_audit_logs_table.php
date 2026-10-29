<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            // restrictOnDelete, not nullOnDelete: the log is append-only, and nullOnDelete would rewrite rows.
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // HMAC of the IP, never the IP itself (security.md section 7).
            $table->string('ip_hash', 64)->nullable();
            // Safe facts only (status from/to). Never health text.
            $table->json('metadata')->nullable();
            // No updated_at: rows are never changed.
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
