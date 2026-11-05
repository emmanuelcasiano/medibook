<?php

declare(strict_types=1);

namespace App\Actions\Intake;

use App\Models\IntakeSummary;

final class GetTokensUsedToday
{
    /**
     * Input plus output tokens of every summary row made since the clinic's day began. Failed rows count too, because
     * the model was paid for. Rows with no tokens (`skipped`) add nothing.
     *
     * One query, used by `GenerateIntakeSummary` (the cap check) and `GetAiUsage` (the admin screen), so the screen can't
     * show a number the cap check does not use.
     */
    public function handle(): int
    {
        // The column is UTC and the clinic day starts at midnight in Manila, so the bound is converted to UTC first.
        $dayStart = now((string) config('app.clinic_timezone'))->startOfDay()->utc();

        return (int) IntakeSummary::query()
            ->where('created_at', '>=', $dayStart)
            ->toBase()
            ->selectRaw('coalesce(sum(coalesce(input_tokens, 0) + coalesce(output_tokens, 0)), 0) as total')
            ->value('total');
    }
}
