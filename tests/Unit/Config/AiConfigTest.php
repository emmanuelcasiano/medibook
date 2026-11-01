<?php

declare(strict_types=1);

// Config only: no route, no user, no database. So there is no denied-access test, and the "validation" test checks
// that the values have the right types and that the provider is one the SDK knows. No test calls a real API.

use Tests\TestCase;

uses(TestCase::class);

it('has an ai block with the four settings', function () {
    $ai = config('medibook.ai');

    expect($ai)->toHaveKeys(['enabled', 'provider', 'model', 'daily_token_cap'])
        ->and($ai['enabled'])->toBeBool()
        ->and($ai['provider'])->toBeString()->not->toBeEmpty()
        ->and($ai['daily_token_cap'])->toBeInt()->toBeGreaterThan(0);
});

it('is switched off in the test environment so no test can reach a provider', function () {
    expect(config('medibook.ai.enabled'))->toBeFalse()
        ->and(config('ai.providers.gemini.key'))->toBeEmpty()
        ->and(config('ai.providers.openai.key'))->toBeEmpty()
        ->and(config('ai.providers.anthropic.key'))->toBeEmpty();
});

it('names a provider that exists in config/ai.php', function () {
    expect(config('ai.providers'))->toHaveKey(config('medibook.ai.provider'));
});

it('can be turned off and on from config without a code change', function () {
    config(['medibook.ai.enabled' => true]);
    expect(config('medibook.ai.enabled'))->toBeTrue();

    config(['medibook.ai.enabled' => false]);
    expect(config('medibook.ai.enabled'))->toBeFalse();
});

it('lets each supported provider be chosen by name', function (string $provider) {
    config(['medibook.ai.provider' => $provider]);

    expect(config('ai.providers'))->toHaveKey(config('medibook.ai.provider'));
})->with(['gemini', 'openai', 'anthropic']);
