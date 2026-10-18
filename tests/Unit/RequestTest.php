<?php
namespace Tests\Unit;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    private static function request(array $server = [], array $get = [], array $post = []): Request
    {
        return new Request($server + ['REMOTE_ADDR' => '198.51.100.7'], $get, $post, []);
    }

    public function testHeadIsTreatedAsGet(): void
    {
        $this->assertSame('GET', self::request(['REQUEST_METHOD' => 'HEAD'])->method());
        $this->assertTrue(self::request(['REQUEST_METHOD' => 'post'])->isPost());
    }

    public function testArrayParametersAreIgnoredWhereStringsAreExpected(): void
    {
        $request = self::request([], ['v' => ['x']], ['name' => ['x'], 'groups' => ['1', '2', ['nested']], 'one' => 'scalar']);
        $this->assertSame('default', $request->query('v', 'default'));
        $this->assertNull($request->input('name'));
        $this->assertSame(['1', '2'], $request->inputList('groups'));
        $this->assertSame([], $request->inputList('one'));
    }

    public function testClientIpIgnoresForwardedForWithoutATrustedProxy(): void
    {
        $request = self::request(['HTTP_X_FORWARDED_FOR' => '203.0.113.9']);
        $this->assertSame('198.51.100.7', $request->clientIp());
        $this->assertSame('198.51.100.7', $request->clientIp(['10.0.0.1']));
    }

    public function testClientIpWalksTheForwardedChainFromTrustedProxies(): void
    {
        $request = self::request(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9, 10.0.0.2']);
        // The left-most entry can be forged by the client: stop at the first untrusted hop
        $this->assertSame('203.0.113.9', $request->clientIp(['10.0.0.1', '10.0.0.2']));
    }

    public function testClientIpStopsAtGarbage(): void
    {
        $request = self::request(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip']);
        $this->assertSame('10.0.0.1', $request->clientIp(['10.0.0.1']));
    }

    public function testHttpsDetectionAndOrigin(): void
    {
        $this->assertSame('http://example.org', self::request(['SERVER_NAME' => 'example.org', 'SERVER_PORT' => '80'])->origin());
        $this->assertSame('https://example.org', self::request(['SERVER_NAME' => 'example.org', 'SERVER_PORT' => '80',
            'HTTP_X_FORWARDED_PROTO' => 'https'])->origin());
        $this->assertSame('http://example.org:8080', self::request(['SERVER_NAME' => 'example.org', 'SERVER_PORT' => '8080'])->origin());
    }

    public function testJsonDetectionAndUserAgentLength(): void
    {
        $this->assertTrue(self::request(['HTTP_ACCEPT' => 'application/json'])->wantsJson());
        $this->assertFalse(self::request(['HTTP_ACCEPT' => 'text/html'])->wantsJson());
        $this->assertSame(255, mb_strlen(self::request(['HTTP_USER_AGENT' => str_repeat('a', 400)])->userAgent()));
    }
}
