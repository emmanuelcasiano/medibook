<?php

declare(strict_types=1);

// DetectEmergency is a pure function: no route, no user, no database. So there is no denied-access test, and the
// "validation" test is about odd input (empty, null, bad bytes) that must never throw or hide an emergency.

use App\Actions\Intake\DetectEmergency;
use Tests\TestCase;

uses(TestCase::class);

function detectsEmergency(string ...$texts): bool
{
    return app(DetectEmergency::class)->handle(array_values($texts));
}

it('detects every word in the configured list', function () {
    $words = config('medibook.emergency_words');

    expect($words)->not->toBeEmpty();

    foreach ($words as $word) {
        expect(detectsEmergency($word))->toBeTrue("'{$word}' was not detected")
            ->and(detectsEmergency(strtoupper($word)))->toBeTrue("'{$word}' was not detected in capitals")
            ->and(detectsEmergency("Hello doc, since last night I have {$word}, please help."))->toBeTrue("'{$word}' was not detected inside a sentence");
    }
});

it('keeps the words PROJECT.md F5 asks for in the list', function (string $phrase) {
    expect(detectsEmergency($phrase))->toBeTrue();
})->with([
    'chest pain',
    "can't breathe",
    'difficulty breathing',
    'unconscious',
    'severe bleeding',
    'stroke',
    'suicide',
    'overdose',
    'seizure',
]);

it('detects Filipino and Taglish phrases', function (string $text) {
    expect(detectsEmergency($text))->toBeTrue();
})->with([
    'hindi makahinga po ako',
    'Hirap huminga ang anak ko',
    'May sakit sa dibdib si Lolo',
    'nahimatay siya kanina',
    'I want to magpakamatay',
]);

it('ignores letter case, apostrophe style, punctuation and extra whitespace', function (string $text) {
    expect(detectsEmergency($text))->toBeTrue();
})->with([
    'upper case' => 'CHEST PAIN',
    'mixed case' => 'ChEsT pAiN',
    'straight apostrophe' => "I can't breathe",
    'curly apostrophe' => 'I can’t breathe',
    'no apostrophe' => 'I cant breathe',
    'double space' => 'chest  pain',
    'new line between words' => "chest\npain",
    'tab between words' => "chest\tpain",
    'hyphen' => 'chest-pain',
    'trailing punctuation' => 'Is this a stroke?!',
    'plural' => 'she had two seizures',
    'past tense' => 'he took an overdosed amount',
]);

it('does not match near misses', function (string $text) {
    expect(detectsEmergency($text))->toBeFalse();
})->with([
    'one word of a phrase: chest' => 'My chest feels a bit itchy from the rash',
    'one word of a phrase: pain' => 'Mild pain in my left knee',
    'one word of a phrase: breathing' => 'Breathing exercises help me sleep',
    'can breathe is not cant breathe' => 'I can breathe fine',
    'phrase ends inside a longer word' => 'My keystroke counter is broken',
    'words in the wrong order' => 'The pain I felt in the pool was not in my chest',
    'similar word: seized' => 'The customs office seized my package',
    'similar word: overdo' => 'I tend to overdo it at the gym',
    'plain visit reason' => 'Annual check-up and a flu shot',
    'allergy answer' => 'Penicillin, peanuts',
    'medicine answer' => 'Metformin 500mg twice a day',
]);

it('checks each text on its own, so words cannot join across two fields', function () {
    expect(detectsEmergency('my chest', 'pain is gone'))->toBeFalse()
        ->and(detectsEmergency('headache', 'since yesterday I have chest pain'))->toBeTrue();
});

it('returns false for empty input and never throws', function (array $texts) {
    expect(app(DetectEmergency::class)->handle($texts))->toBeFalse();
})->with([
    'no texts' => [[]],
    'empty string' => [['']],
    'whitespace only' => [["  \n\t "]],
    'punctuation only' => [['?!... --']],
    'null answers' => [[null, null]],
]);

it('still detects an emergency when the text has invalid UTF-8 bytes', function () {
    expect(detectsEmergency("chest pain\xFF\xFE"))->toBeTrue()
        ->and(detectsEmergency("\xFF\xFE I cant breathe"))->toBeTrue();
});

it('skips a null answer and still checks the others', function () {
    expect(app(DetectEmergency::class)->handle([null, 'severe bleeding']))->toBeTrue();
});

it('ignores blank entries in the configured list instead of matching everything', function () {
    config()->set('medibook.emergency_words', ['', '   ', '...']);

    expect(detectsEmergency('Annual check-up'))->toBeFalse();
});

it('detects nothing when the list is empty', function () {
    config()->set('medibook.emergency_words', []);

    expect(detectsEmergency('chest pain'))->toBeFalse();
});
