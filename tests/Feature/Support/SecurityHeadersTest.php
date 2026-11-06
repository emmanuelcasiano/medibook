<?php

declare(strict_types=1);

// Step 14.4: SecurityHeaders puts the browser-hardening headers from security.md section 4 on every web response. It has
// no input, no Policy and no write, so there is no denied-access or validation test. The headers are read on a normal
// page, on a redirect (what a signed-out visitor gets from a protected page), on a 404 and on a 429. Strict-Transport-Security and
// Content-Security-Policy are left out on purpose (see the class), so a test checks that nothing adds them by accident.

dataset('security headers', [
    'nosniff' => ['X-Content-Type-Options', 'nosniff'],
    'no framing' => ['X-Frame-Options', 'DENY'],
    'referrer policy' => ['Referrer-Policy', 'strict-origin-when-cross-origin'],
    'permissions policy' => ['Permissions-Policy', 'camera=(), microphone=(), geolocation=()'],
]);

it('sends the header on a page', function (string $header, string $value): void {
    $this->get(route('home'))->assertOk()->assertHeader($header, $value);
})->with('security headers');

it('sends the header on a redirect', function (string $header, string $value): void {
    $this->get(route('dashboard'))->assertRedirect()->assertHeader($header, $value);
})->with('security headers');

// `auth` and `throttle` run ahead of group middleware, and an unknown URL never reaches the `web` group. These two
// responses are what a header middleware kept in the `web` group would have missed.
it('sends the header on a page that does not exist', function (string $header, string $value): void {
    $this->get('/no-such-page')->assertNotFound()->assertHeader($header, $value);
})->with('security headers');

it('sends the header on a refused request', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('register.store'), [])->assertRedirect();
    }

    $this->post(route('register.store'), [])->assertTooManyRequests()->assertHeader('X-Frame-Options', 'DENY');
});

it('leaves out the headers that need HTTPS or a tested policy', function (): void {
    $response = $this->get(route('home'));

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});
