<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * The TrustProxies middleware keeps its trusted proxy list on a static of the Request class, so without this the
     * `127.0.0.1` set by one HTTP test would still be there in the next test of the same process, and a request with no
     * IP (a system event) would then make Symfony throw.
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
