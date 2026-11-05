<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The daily token cap (every summary) and the admin AI usage screen (every summary this month) both filter on
     * `created_at`. Without an index each summary run and each page view scans the whole table. This only adds an index,
     * so no data changes.
     */
    public function up(): void
    {
        Schema::table('intake_summaries', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('intake_summaries', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
