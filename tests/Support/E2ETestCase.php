<?php
namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/** HTTP tests against the running development site (see tests/README.md) */
abstract class E2ETestCase extends TestCase
{
    protected E2EEnvironment $env;
    protected Fixtures $fx;

    protected function setUp(): void
    {
        $env = E2EEnvironment::get();
        if ($env === null) {
            $this->markTestSkipped(E2EEnvironment::unavailableReason());
        }
        $this->env = $env;
        $this->fx = new Fixtures();
        $env->mail->clear();
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    /** A new browser (empty cookie jar) */
    protected function client(string $userAgent = HttpClient::USER_AGENT): HttpClient
    {
        return new HttpClient($this->env->baseUrl, $userAgent);
    }

    /** A browser logged in as $user */
    protected function loggedIn(array $user, bool $remember = false): HttpClient
    {
        $client = $this->client();
        $response = $client->login($user, $remember);
        $this->assertSame(302, $response->status, "Login of {$user['username']} failed");
        return $client;
    }

    /** Absolute path of an application route, as used in Location headers */
    protected function url(string $path = ''): string
    {
        return $this->env->baseUrl . $path;
    }

    protected function basePath(): string
    {
        return parse_url($this->env->baseUrl, PHP_URL_PATH);
    }

    protected function assertRedirect(Response $response, string $path, string $message = ''): void
    {
        $this->assertSame(302, $response->status, $message ?: "Expected a redirect to $path");
        $this->assertSame($this->url($path), $response->location(), $message);
    }

    protected function assertAlert(Response $response, string $fragment): void
    {
        $alerts = implode(' | ', $response->alerts());
        $this->assertStringContainsString($fragment, $alerts, "Alerts: $alerts");
    }

    /** Messages captured by the mail sink for $address */
    protected function mailsTo(string $address): array
    {
        // Mail is sent synchronously by the request, but give the sink a moment to write it
        usleep(150000);
        return $this->env->mail->to($address);
    }
}
