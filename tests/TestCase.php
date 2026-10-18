<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Override;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    /**
     * Production serves every request in a fresh PHP process (FrankenPHP `php_server`, no worker mode), so Livewire's
     * per-request state never outlives a request. Its static flags (e.g. "a component was rendered this request")
     * survive in the test process across requests and even across tests, while the app and its singletons are new:
     * a Livewire page served by one test then had Livewire's assets injected into a later, Livewire-free page. Only
     * Livewire::test() resets that state, so reset it at the start of every test and before every HTTP request.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        Livewire::flushState();
    }

    /**
     * @param  string  $method
     * @param  string  $uri
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     * @param  string|null  $content
     * @return TestResponse<Response>
     */
    #[Override]
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        Livewire::flushState();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
