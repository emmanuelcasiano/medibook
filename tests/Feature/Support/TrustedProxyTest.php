<?php

declare(strict_types=1);

// Step 14.4: in production nginx ends TLS on the same server and forwards plain HTTP, so the app must believe
// `X-Forwarded-Proto: https` from 127.0.0.1 (bootstrap/app.php trustProxies) and from nowhere else. A signed-out visitor
// who opens a protected page is redirected to the login page, and that URL shows which scheme the app believes in. There
// is no Policy and no input here, so no denied-access or validation test.

it('builds https links when nginx on the same server says the visit was https', function (): void {
    $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get(route('dashboard'));

    expect($response->headers->get('Location'))->toStartWith('https://');
});

it('ignores the header from any other address', function (): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get(route('dashboard'));

    expect($response->headers->get('Location'))->toStartWith('http://');
});

it('builds http links when no proxy header is sent', function (): void {
    expect($this->get(route('dashboard'))->headers->get('Location'))->toStartWith('http://');
});
