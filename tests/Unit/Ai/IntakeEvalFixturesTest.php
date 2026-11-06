<?php

declare(strict_types=1);

// The eval fixtures are data and cost nothing to check, so this file never calls a model. It is not a route or an
// Action, so there is no denied-access test: the "denied" case here is the emergency split. A normal case that trips
// the emergency word list would never reach the model and would silently drop out of the eval (and the reverse), so
// both directions are tested. The "validation" case is the shape checker, tested with bad input.

use App\Actions\Intake\DetectEmergency;
use App\Ai\Evals\IntakeEvalFixtures;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

function evalFixtureLoader(?string $directory = null): IntakeEvalFixtures
{
    return new IntakeEvalFixtures($directory ?? dirname(__DIR__, 2).'/Evals/fixtures');
}

/**
 * The fixtures as a Pest dataset: file name => [file name, fixture]. Empty `$categories` means all of them.
 *
 * @return array<string, array{string, array<string, mixed>}>
 */
function fixturesIn(string ...$categories): array
{
    $cases = [];

    foreach (evalFixtureLoader()->all() as $name => $fixture) {
        if ($categories === [] || in_array($fixture['category'] ?? null, $categories, true)) {
            $cases[$name] = [$name, $fixture];
        }
    }

    return $cases;
}

/** Everything the patient wrote, in lower case, for "is this word in the answers" checks. */
function evalAnswerText(array $fixture): string
{
    return mb_strtolower(implode("\n", array_filter($fixture['answers'], is_string(...))));
}

/** The keys of the errors the shape checker gives for a fixture ([] when it is valid). */
function shapeErrors(array $fixture): array
{
    try {
        evalFixtureLoader()->validate($fixture);
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

it('has the planned mix: 8 normal, 2 vague, 3 injection, 2 diagnosis and the emergency cases', function () {
    $counts = collect(evalFixtureLoader()->all())->countBy('category')->all();

    expect($counts)->toBe(['diagnosis' => 2, 'emergency' => 3, 'injection' => 3, 'normal' => 8, 'vague' => 2])
        // The 15 the model is scored on, plus the emergency cases it must never see.
        ->and(array_sum($counts) - $counts['emergency'])->toBe(15);
});

it('has a valid shape and an id that matches its file name', function (string $name, array $fixture) {
    expect(shapeErrors($fixture))->toBe([])
        ->and($fixture['id'])->toBe($name);
})->with(fn () => fixturesIn());

it('flags every emergency case, wherever the phrase is written', function (string $name, array $fixture) {
    $answers = $fixture['answers'];

    $flagged = app(DetectEmergency::class)->handle([
        $answers['reason'], $answers['symptoms'], $answers['allergies'], $answers['medicines'],
    ]);

    expect($flagged)->toBeTrue();
})->with(fn () => fixturesIn('emergency'));

it('does not flag a case the model is scored on, or it would never reach the model', function (string $name, array $fixture) {
    $answers = $fixture['answers'];

    $flagged = app(DetectEmergency::class)->handle([
        $answers['reason'], $answers['symptoms'], $answers['allergies'], $answers['medicines'],
    ]);

    expect($flagged)->toBeFalse();
})->with(fn () => fixturesIn('normal', 'vague', 'injection', 'diagnosis'));

it('only expects facts the patient wrote', function (string $name, array $fixture) {
    $text = evalAnswerText($fixture);

    // The facts none of whose words the patient wrote. An empty list is a pass, so the test always asserts.
    $invented = collect($fixture['checks']['must_include'])
        ->reject(fn (array $fact): bool => collect($fact)->contains(fn (string $word): bool => str_contains($text, mb_strtolower($word))))
        ->values()
        ->all();

    expect($invented)->toBe([], "{$name} expects facts that are not in the answers");
})->with(fn () => fixturesIn('normal', 'vague', 'injection', 'diagnosis'));

it('expects "Not given" only where the patient left the field empty', function (string $name, array $fixture) {
    $answers = $fixture['answers'];

    // The summary's `allergies_and_meds` covers two answers, so it can only be "Not given" if both are empty.
    $wrong = collect($fixture['checks']['expect_not_given'])
        ->filter(fn (string $field): bool => match ($field) {
            'allergies_and_meds' => filled($answers['allergies']) || filled($answers['medicines']),
            'chief_complaint' => filled($answers['reason']),
        })
        ->values()
        ->all();

    expect($wrong)->toBe([], "{$name} expects \"Not given\" for a field the patient filled in");
})->with(fn () => fixturesIn('normal', 'vague', 'injection', 'diagnosis'));

it('uses a canary the injection really asks for, so the check is not empty', function (string $name, array $fixture) {
    $text = evalAnswerText($fixture);

    $asked = collect($fixture['checks']['must_not_include'])
        ->contains(fn (string $word): bool => str_contains($text, mb_strtolower($word)));

    expect($asked)->toBeTrue("No must_not_include word of {$name} appears in what the patient wrote");
})->with(fn () => fixturesIn('injection'));

it('forbids only words the patient did not write, so a faithful summary can pass', function (string $name, array $fixture) {
    $text = evalAnswerText($fixture);

    $written = collect($fixture['checks']['must_not_include'])
        ->filter(fn (string $word): bool => str_contains($text, mb_strtolower($word)))
        ->values()
        ->all();

    expect($written)->toBe([], "{$name} forbids words the patient wrote");
})->with(fn () => fixturesIn('vague', 'diagnosis'));

it('rejects a fixture with a bad shape', function (string $field, Closure $break) {
    $fixture = evalFixtureLoader()->all()['normal-01-dry-cough'];

    expect(shapeErrors($break($fixture)))->toContain($field);
})->with([
    'a missing answer' => ['answers.symptoms', function (array $f) {
        unset($f['answers']['symptoms']);

        return $f;
    }],
    'an unknown answer' => ['answers', function (array $f) {
        $f['answers']['phone'] = '09171234567';

        return $f;
    }],
    'a duration that is not in the select' => ['answers.duration', function (array $f) {
        $f['answers']['duration'] = 'forever';

        return $f;
    }],
    'a reason longer than the form allows' => ['answers.reason', function (array $f) {
        $f['answers']['reason'] = str_repeat('a', 501);

        return $f;
    }],
    'a yes/no answer that is text' => ['answers.fever', function (array $f) {
        $f['answers']['fever'] = 'maybe';

        return $f;
    }],
    'an unknown category' => ['category', function (array $f) {
        $f['category'] = 'weird';

        return $f;
    }],
    'an id that is not kebab-case' => ['id', function (array $f) {
        $f['id'] = 'Normal_01';

        return $f;
    }],
    'a misspelled check key' => ['checks', function (array $f) {
        $f['checks']['must_includes'] = $f['checks']['must_include'];
        unset($f['checks']['must_include']);

        return $f;
    }],
    'a normal case with no facts' => ['checks.must_include', function (array $f) {
        $f['checks']['must_include'] = [];

        return $f;
    }],
    'a fact that is not a list of words' => ['checks.must_include.0', function (array $f) {
        $f['checks']['must_include'][0] = 'cough';

        return $f;
    }],
    'a Not given field the summary does not have' => ['checks.expect_not_given.0', function (array $f) {
        $f['checks']['expect_not_given'] = ['key_points'];

        return $f;
    }],
    'an emergency case that does not say the model is never called' => ['checks.never_prompted', function (array $f) {
        $f['category'] = 'emergency';
        $f['checks'] = [];

        return $f;
    }],
    'an emergency case that says never_prompted is false' => ['checks.never_prompted', function (array $f) {
        $f['category'] = 'emergency';
        $f['checks'] = ['never_prompted' => false];

        return $f;
    }],
    'an emergency case with a summary check it can never use' => ['checks', function (array $f) {
        $f['category'] = 'emergency';
        $f['checks'] = ['never_prompted' => true, 'must_include' => [['cough']]];

        return $f;
    }],
    'a vague case that expects no Not given field' => ['checks.expect_not_given', function (array $f) {
        $f['category'] = 'vague';
        $f['checks']['expect_not_given'] = [];

        return $f;
    }],
    'a vague case that forbids no invented detail' => ['checks.must_not_include', function (array $f) {
        $f['category'] = 'vague';
        $f['checks']['must_not_include'] = [];

        return $f;
    }],
    'an injection case with no canary' => ['checks.must_not_include', function (array $f) {
        $f['category'] = 'injection';
        $f['checks']['must_not_include'] = [];

        return $f;
    }],
    'a diagnosis case with no facts' => ['checks.must_include', function (array $f) {
        $f['category'] = 'diagnosis';
        $f['checks']['must_include'] = [];

        return $f;
    }],
    'a description that is too long' => ['description', function (array $f) {
        $f['description'] = str_repeat('a', 201);

        return $f;
    }],
]);

it('reads the .json files in file name order and ignores other files', function () {
    $directory = sys_get_temp_dir().'/medibook-evals-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/b-second.json', '{"id": "b-second"}');
    file_put_contents($directory.'/a-first.json', '{"id": "a-first"}');
    file_put_contents($directory.'/notes.txt', 'not a fixture');

    try {
        $names = array_keys(evalFixtureLoader($directory)->all());
    } finally {
        array_map(unlink(...), glob($directory.'/*'));
        rmdir($directory);
    }

    expect($names)->toBe(['a-first', 'b-second']);
});

it('returns no cases for an empty or missing folder', function () {
    expect(evalFixtureLoader(sys_get_temp_dir().'/medibook-evals-missing-'.bin2hex(random_bytes(4)))->all())->toBe([]);
});

it('stops on a file that is not valid JSON or not one JSON object', function (string $contents, string $exception) {
    $directory = sys_get_temp_dir().'/medibook-evals-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/broken.json', $contents);

    try {
        expect(fn () => evalFixtureLoader($directory)->all())->toThrow($exception);
    } finally {
        unlink($directory.'/broken.json');
        rmdir($directory);
    }
})->with([
    'broken JSON' => ['{"id": ', JsonException::class],
    'a list' => ['[1, 2, 3]', UnexpectedValueException::class],
    'a plain value' => ['"hello"', UnexpectedValueException::class],
]);
