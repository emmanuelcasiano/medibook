<?php

declare(strict_types=1);

namespace App\Ai\Evals;

use App\Enums\SummaryGap;
use App\Http\Requests\Intake\SaveIntakeRequest;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * Reads and checks the intake eval cases in `tests/Evals/fixtures/*.json` (ai.md section 7). A case is one fake intake
 * plus the checks to run on the model's summary of it. The shape is the contract between the fixture files and the
 * eval command (`medibook:eval-intake`), so a typo in a file fails here, not halfway through a paid run.
 *
 * The answers must be valid for the real form (`SaveIntakeRequest`), so a case can never test an intake the app would
 * refuse to save. The checks are:
 *  - must_include:     a list of facts. A fact is a list of words, and any one of them in the summary counts
 *                      (case-insensitive, part of a word is enough, so "lift" matches "lifting").
 *  - must_not_include: words that must not appear in the summary (an injection's canary, or an invented detail).
 *  - expect_gaps:      `SummaryGap` keys that must be in the summary's `gaps`, because the patient did not say them.
 *  - forbid_gaps:      `SummaryGap` keys that must not be in `gaps`, because the patient did say them.
 *  - never_prompted:   emergency cases only. The model must not be called at all.
 * The checks every case gets (no diagnosis, no medicine advice) belong to the command, not to the files.
 */
final class IntakeEvalFixtures
{
    /** `emergency` cases never reach the model. The other four are the cases the model is scored on. */
    public const array CATEGORIES = ['normal', 'vague', 'injection', 'diagnosis', 'emergency'];

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? base_path('tests/Evals/fixtures');
    }

    /**
     * Every case in the folder, keyed by file name without `.json`, in file name order. A file that is not valid JSON
     * or not a JSON object throws, because a half-read eval would give a score that means nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $files = glob($this->directory.'/*.json') ?: [];
        sort($files);

        $fixtures = [];

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded) || array_is_list($decoded)) {
                throw new UnexpectedValueException(basename($file).' must hold one JSON object.');
            }

            $fixtures[basename($file, '.json')] = $decoded;
        }

        return $fixtures;
    }

    /**
     * Check the shape of one case.
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed> The fixture as it was checked.
     *
     * @throws ValidationException
     */
    public function validate(array $fixture): array
    {
        $category = $fixture['category'] ?? null;

        $validator = Validator::make($fixture, $this->rules(is_string($category) ? $category : ''));
        $validator->after(fn (ValidatorContract $validator) => $this->checkKeys($validator, $fixture, $category));

        return $validator->validate();
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function rules(string $category): array
    {
        $rules = [
            'id' => ['required', 'string', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'description' => ['required', 'string', 'max:200'],
            'answers' => ['required', 'array'],
            'checks' => ['required', 'array'],
        ];

        // The form's own rules, so the lengths and the `duration` values are written once. `present` is added because
        // `nullable` alone lets a missing key pass, and every answer must be written out, even as null.
        foreach ((new SaveIntakeRequest)->rules() as $key => $answerRules) {
            $rules["answers.{$key}"] = ['present', ...$answerRules];
        }

        if ($category === 'emergency') {
            return [...$rules, 'checks.never_prompted' => ['required', 'accepted']];
        }

        // Each kind of case must check what it is there for: facts for a normal or diagnosis case, invented details or
        // a canary for the others, and the missing gaps for a vague one.
        $needsFacts = in_array($category, ['normal', 'diagnosis'], true);
        $needsForbidden = in_array($category, ['vague', 'injection', 'diagnosis'], true);

        return [
            ...$rules,
            'checks.must_include' => ['present', 'array', ...($needsFacts ? ['min:1'] : [])],
            'checks.must_include.*' => ['array', 'min:1'],
            'checks.must_include.*.*' => ['string', 'min:1', 'max:100'],
            'checks.must_not_include' => ['present', 'array', ...($needsForbidden ? ['min:1'] : [])],
            'checks.must_not_include.*' => ['string', 'min:1', 'max:100'],
            'checks.expect_gaps' => ['present', 'array', ...($category === 'vague' ? ['min:1'] : [])],
            'checks.expect_gaps.*' => ['distinct', Rule::enum(SummaryGap::class)],
            'checks.forbid_gaps' => ['present', 'array'],
            'checks.forbid_gaps.*' => ['distinct', Rule::enum(SummaryGap::class)],
        ];
    }

    /**
     * The validator accepts extra keys, so a misspelled check (`must_includes`) would pass and silently check nothing.
     * `answers` and `checks` must hold exactly the known keys.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function checkKeys(ValidatorContract $validator, array $fixture, mixed $category): void
    {
        $expected = [
            'answers' => array_keys((new SaveIntakeRequest)->rules()),
            'checks' => $category === 'emergency' ? ['never_prompted'] : ['expect_gaps', 'forbid_gaps', 'must_include', 'must_not_include'],
        ];

        foreach ($expected as $field => $keys) {
            $given = $fixture[$field] ?? null;

            if (! is_array($given)) {
                continue;
            }

            $given = array_keys($given);
            sort($given);
            sort($keys);

            if ($given !== $keys) {
                $validator->errors()->add($field, "The {$field} must have exactly these keys: ".implode(', ', $keys).'.');
            }
        }
    }
}
