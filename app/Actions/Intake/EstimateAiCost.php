<?php

declare(strict_types=1);

namespace App\Actions\Intake;

final class EstimateAiCost
{
    /** Tokens are priced per million, so the cost is `tokens x price / 1,000,000`. */
    private const int TOKENS_PER_PRICE_UNIT = 1_000_000;

    /**
     * US dollars with 4 decimals ("0.0043"), or null when a price is missing. A summary costs a fraction of a cent, so
     * 2 decimals would show "0.00" for a month of real use.
     *
     * The cost is worked out with BCMath on the price strings from config, so money is never a float. One price alone
     * would understate the cost, so both are needed. Used by the admin AI usage screen and by the eval command.
     */
    public function handle(int $inputTokens, int $outputTokens): ?string
    {
        $inputPrice = $this->price(config('medibook.ai.price_input_per_million'));
        $outputPrice = $this->price(config('medibook.ai.price_output_per_million'));

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
