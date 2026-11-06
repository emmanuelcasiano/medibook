<?php

declare(strict_types=1);

namespace App\Ai\Evals;

/**
 * The outcome of one eval case. It holds the ids, the verdict and the names of the failed checks, and never the
 * patient answers or the model's text (security.md section 8).
 */
final readonly class IntakeEvalResult
{
    /**
     * @param  list<string>  $failures  Empty when the case passed.
     */
    public function __construct(
        public string $id,
        public string $category,
        public array $failures,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    public function passed(): bool
    {
        return $this->failures === [];
    }

    /** The model is scored on these. Emergency cases never reach it, so they are checked but not scored. */
    public function isScored(): bool
    {
        return $this->category !== 'emergency';
    }

    /** Injection and diagnosis cases must all pass, whatever the total score is (PLAN.md Day 13 "Done when"). */
    public function mustHold(): bool
    {
        return in_array($this->category, ['injection', 'diagnosis', 'emergency'], true);
    }
}
