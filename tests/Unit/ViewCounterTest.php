<?php
namespace Tests\Unit;

use App\Auth\Throttle;
use App\Stats\ViewCounter;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Token and threshold logic; rejections that happen before any database access */
class ViewCounterTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const UA = 'Mozilla/5.0 Chrome/155.0';

    private function counter(string $secret = self::SECRET): ViewCounter
    {
        // These cases return before touching the database
        $db = new PDO('sqlite::memory:');
        return new ViewCounter($db, new Throttle($db), $secret);
    }

    private function video(string $id = 'abcdefghijkl', ?float $duration = 600.0): array
    {
        return ['id' => $id, 'duration' => $duration, 'visibility' => 'public', 'collection_id' => 0];
    }

    public static function thresholds(): array
    {
        return [
            'long video' => [629.9, 30.0],
            'exactly one minute' => [60.0, 30.0],
            'short video: half' => [20.0, 10.0],
            'very short' => [3.0, 1.5],
            'unknown duration' => [null, 30.0],
        ];
    }

    #[DataProvider('thresholds')]
    public function testThresholdIs30SecondsOrHalfTheVideo(?float $duration, float $expected): void
    {
        $this->assertSame($expected, ViewCounter::threshold($duration));
    }

    public function testDisabledWithoutASecret(): void
    {
        $this->assertFalse($this->counter('')->enabled());
        $this->assertTrue($this->counter()->enabled());
        $this->assertSame(ViewCounter::REJECTED, $this->counter('')->record($this->video(), 'x.y', null, '192.0.2.1', self::UA));
    }

    public function testTokensAreBoundToTheVideoAndTime(): void
    {
        $counter = $this->counter();
        $token = $counter->token('abcdefghijkl', 1000);
        $this->assertMatchesRegularExpression('/^1000\.[0-9a-f]{64}$/', $token);
        $this->assertNotSame($token, $counter->token('abcdefghijkm', 1000));
        $this->assertNotSame($token, $counter->token('abcdefghijkl', 1001));
        $this->assertNotSame($token, $this->counter(str_repeat('f', 64))->token('abcdefghijkl', 1000));
    }

    public static function rejectedReports(): array
    {
        return [
            'bot user agent' => ['valid', 'Googlebot/2.1'],
            'command-line tool' => ['valid', 'curl/8.14.1'],
            'empty user agent' => ['valid', ''],
            'malformed token' => ['garbage', self::UA],
            'forged signature' => ['forged', self::UA],
            'token of another video' => ['other-video', self::UA],
            'threshold not elapsed' => ['fresh', self::UA],
            'token too old' => ['expired', self::UA],
        ];
    }

    #[DataProvider('rejectedReports')]
    public function testRejectsInvalidReports(string $tokenKind, string $userAgent): void
    {
        $counter = $this->counter();
        $video = $this->video();
        $token = match ($tokenKind) {
            'valid' => $counter->token($video['id'], time() - 60),
            'garbage' => 'garbage',
            'forged' => (time() - 60) . '.' . str_repeat('0', 64),
            'other-video' => $counter->token('zzzzzzzzzzzz', time() - 60),
            'fresh' => $counter->token($video['id'], time()),
            'expired' => $counter->token($video['id'], time() - 7 * 3600),
        };
        $this->assertSame(ViewCounter::REJECTED, $counter->record($video, $token, null, '192.0.2.1', $userAgent));
    }
}
