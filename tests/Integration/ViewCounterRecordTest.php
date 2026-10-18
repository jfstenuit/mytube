<?php
namespace Tests\Integration;

use App\Auth\Throttle;
use App\Core\Config;
use App\Models\User;
use App\Models\Video;
use App\Stats\ViewCounter;
use Tests\Support\IntegrationTestCase;

/** Recording views with backdated (but correctly signed) tokens */
class ViewCounterRecordTest extends IntegrationTestCase
{
    private const UA = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/155.0';
    private ViewCounter $counter;
    private string $videoId;

    protected function setUp(): void
    {
        parent::setUp();
        if (strlen((string) Config::get('app.secret')) < 32) {
            $this->markTestSkipped('APP_SECRET is not set: view counting is disabled.');
        }
        $this->counter = new ViewCounter($this->fx->db(), new Throttle($this->fx->db()), Config::get('app.secret'));
        $this->videoId = $this->fx->video();  // 4 s clip: threshold 2 s
    }

    private function record(?array $user = null, string $ip = '192.0.2.60', string $ua = self::UA, int $age = 10): string
    {
        $video = Video::find($this->fx->db(), $this->videoId);
        $token = $this->counter->token($this->videoId, time() - $age);
        return $this->counter->record($video, $token, $user ? User::find($this->fx->db(), $user['id']) : null, $ip, $ua);
    }

    private function viewCount(): int
    {
        return (int) $this->fx->scalar('SELECT view_count FROM videos WHERE id = ?', [$this->videoId]);
    }

    public function testCountsOncePerViewerPerHour(): void
    {
        $this->assertSame(ViewCounter::COUNTED, $this->record());
        $this->assertSame(ViewCounter::DUPLICATE, $this->record());
        $this->assertSame(ViewCounter::COUNTED, $this->record(ua: self::UA . ' Edg/155'), 'another browser is another viewer');
        $this->assertSame(ViewCounter::COUNTED, $this->record(ip: '192.0.2.61'), 'another address is another viewer');
        $this->assertSame(3, $this->viewCount());
        $this->assertSame(3, (int) $this->fx->scalar('SELECT COUNT(*) FROM video_views WHERE video_id = ?', [$this->videoId]));
    }

    public function testRepeatViewsCountAfterAnHour(): void
    {
        $this->assertSame(ViewCounter::COUNTED, $this->record());
        $this->fx->db()->prepare('UPDATE video_views SET viewed_at = NOW() - INTERVAL 61 MINUTE WHERE video_id = ?')->execute([$this->videoId]);
        $this->assertSame(ViewCounter::COUNTED, $this->record());
        $this->assertSame(2, $this->viewCount());
    }

    public function testLoggedInViewersAreIdentifiedByAccount(): void
    {
        $user = $this->fx->user('viewer');
        $this->assertSame(ViewCounter::COUNTED, $this->record($user));
        $this->assertSame(ViewCounter::DUPLICATE, $this->record($user, '192.0.2.62', self::UA . ' Firefox'), 'same account, other device');
        $this->assertSame($user['id'], (int) $this->fx->scalar('SELECT user_id FROM video_views WHERE video_id = ?', [$this->videoId]));
    }

    public function testNoIpAddressIsStored(): void
    {
        $this->record(ip: '192.0.2.63');
        $hash = $this->fx->scalar('SELECT viewer_hash FROM video_views WHERE video_id = ?', [$this->videoId]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertStringNotContainsString('192.0.2.63', $hash);
    }

    public function testThresholdMustHaveElapsed(): void
    {
        // 10-minute video: 30 s of real time must pass between the page and the report
        $this->videoId = $this->fx->video(['duration' => 600]);
        $this->assertSame(ViewCounter::REJECTED, $this->record(age: 0));
        $this->assertSame(ViewCounter::REJECTED, $this->record(age: 20));
        $this->assertSame(ViewCounter::COUNTED, $this->record(age: 30));
    }

    public function testIpCapOfThirtyViewsPerHour(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->assertSame(ViewCounter::COUNTED, $this->record(ua: self::UA . " n$i", ip: '192.0.2.64'));
        }
        $this->assertSame(ViewCounter::REJECTED, $this->record(ua: self::UA . ' n31', ip: '192.0.2.64'));
        $this->assertSame(30, $this->viewCount());
    }
}
