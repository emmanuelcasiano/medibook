<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Intake\GetTokensUsedToday;
use App\Enums\SummaryStatus;
use App\Models\IntakeSummary;

final class GetAiUsage
{
    /** Tokens are priced per million, so the cost is `tokens x price / 1,000,000`. */
    private const int TOKENS_PER_PRICE_UNIT = 1_000_000;

    public function __construct(private readonly GetTokensUsedToday $tokensUsedToday) {}

    /**
     * What the AI summaries cost this month (PROJECT.md F11), as plain data for the admin screen.
     *
     * - "This month" is the clinic month (Asia/Manila). Its bounds are converted to UTC before the query, because
     *   `created_at` holds UTC: a summary made at 00:30 on 1 Nov in Manila is still 31 Oct in the column.
     * - The counts and sums run in SQL (`group by status`). No summary row is loaded, so no summary text (health data) is
     *   ever read. Failed rows count for their tokens too, because the model was paid for. Skipped rows have none.
     * - The cost is worked out with BCMath on the price strings from config, so money is never a float. If either price
     *   is not set, the cost is `null` and the screen says "Price not set", never 0.
     * - The cap numbers use `GetTokensUsedToday`, the same query as the cap check in `GenerateIntakeSummary`.
     *
     * @return array{
     *     month: string,
     *     statuses: list<array{status: string, label: string, count: int}>,
     *     tokens: array{input: int, output: int, total: int},
     *     estimatedCost: string|null,
     *     cap: array{daily: int, usedToday: int, reached: bool},
     * }
     */
    public function handle(): array
    {
        $monthStart = now((string) config('app.clinic_timezone'))->startOfMonth();

        $rows = IntakeSummary::query()
            ->where('created_at', '>=', $monthStart->utc())
            ->where('created_at', '<', $monthStart->addMonth()->utc())
            ->toBase()
            ->selectRaw('status, count(*) as summaries, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // Every status is listed, even with no rows, so the screen always has the same four lines.
        $statuses = array_map(fn (SummaryStatus $status): array => [
            'status' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($rows->get($status->value)->summaries ?? 0),
        ], SummaryStatus::cases());

        $input = (int) $rows->sum('input_tokens');
        $output = (int) $rows->sum('output_tokens');

        $dailyCap = (int) config('medibook.ai.daily_token_cap');
        $usedToday = $this->tokensUsedToday->handle();

        return [
            // A clinic date, `YYYY-MM-DD`: the page names the month from it.
            'month' => $monthStart->format('Y-m-d'),
            'statuses' => $statuses,
            'tokens' => ['input' => $input, 'output' => $output, 'total' => $input + $output],
            'estimatedCost' => $this->estimatedCost($input, $output),
            'cap' => [
                'daily' => $dailyCap,
                'usedToday' => $usedToday,
                'reached' => $usedToday >= $dailyCap,
            ],
        ];
    }

    /**
     * US dollars with 4 decimals ("0.0043"), or null when a price is missing. A summary costs a fraction of a cent, so
     * 2 decimals would show "0.00" for a month of real use.
     */
    private function estimatedCost(int $inputTokens, int $outputTokens): ?string
    {
        $inputPrice = $this->price(config('medibook.ai.price_input_per_million'));
        $outputPrice = $this->price(config('medibook.ai.price_output_per_million'));

        // One price alone would understate the cost, so both are needed.
        if ($inputPrice === null || $outputPrice === null) {
            return null;
        }

        $cost = bcadd(
            bcmul((string) $inputTokens, $inputPrice, 8),
            bcmul((string) $outputTokens, $outputPrice, 8),
            8,
        );

        // Divide once, then round half up to 4 decimals (bcadd alone would cut the digits off).
        $dollars = bcdiv($cost, (string) self::TOKENS_PER_PRICE_UNIT, 10);

        return bcadd(bcadd($dollars, '0.00005', 10), '0', 4);
    }

    /**
     * A price from config as a plain decimal string, or null when it is empty, negative or not a number. The pattern
     * allows digits and one dot only, so "1e3", " 5" and "-0.3" are refused too.
     *
     * @return numeric-string|null
     */
    private function price(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1 || ! is_numeric($value)) {
            return null;
        }

        return $value;
    }
}
