<?php
namespace Tests\Unit;

use App\Media\MediaLibrary;
use PHPUnit\Framework\TestCase;

/** Scanning and locating media in a temporary directory */
class MediaLibraryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mytube-media-test-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        file_put_contents("$this->root/clip.mp4", str_repeat('x', 1000));
        file_put_contents("$this->root/With Space.mp4", 'x');
        file_put_contents("$this->root/UPPER.MP4", 'x');
        file_put_contents("$this->root/raw.mkv", 'x');
        file_put_contents("$this->root/notes.txt", 'x');
        file_put_contents("$this->root/.hidden.mp4", 'x');
        $this->hls('complete', true);
        $this->hls('encoding', false);
        mkdir("$this->root/legacy/manifest", 0777, true);
        file_put_contents("$this->root/legacy/manifest/video.m3u8", "#EXTM3U\n#EXTINF:4,\nseg.ts\n#EXT-X-ENDLIST\n");
        mkdir("$this->root/not-a-video");
        file_put_contents("$this->root/not-a-video/readme.txt", 'x');
    }

    protected function tearDown(): void
    {
        @chmod("$this->root/locked/720p", 0755);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function hls(string $name, bool $finished): void
    {
        mkdir("$this->root/$name/720p", 0777, true);
        file_put_contents("$this->root/$name/master.m3u8", "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\n720p.m3u8\n");
        file_put_contents("$this->root/$name/720p.m3u8", "#EXTM3U\n#EXTINF:4,\n720p/segment_0.ts\n" . ($finished ? "#EXT-X-ENDLIST\n" : ''));
        file_put_contents("$this->root/$name/720p/segment_0.ts", str_repeat('x', 500));
    }

    private function entries(array $registered = []): array
    {
        $entries = [];
        foreach ((new MediaLibrary($this->root))->unregistered($registered) as $entry) {
            $entries[$entry['path']] = $entry;
        }
        return $entries;
    }

    public function testListsSupportedMediaWithTheirState(): void
    {
        $entries = $this->entries();
        $this->assertSame(['mp4', null, 1000], [$entries['clip']['type'], $entries['clip']['problem'], $entries['clip']['size']]);
        $this->assertSame(['hls', null], [$entries['complete']['type'], $entries['complete']['problem']]);
        $this->assertSame(['hls', null], [$entries['legacy']['type'], $entries['legacy']['problem']]);
        $this->assertSame('incomplete', $entries['encoding']['problem']);
        $this->assertSame('unsupported', $entries['raw.mkv']['problem']);
        $this->assertSame('extension_case', $entries['UPPER.MP4']['problem']);
        $this->assertArrayHasKey('With Space', $entries);
    }

    public function testIgnoresHiddenOtherFilesAndPlainDirectories(): void
    {
        $entries = $this->entries();
        $this->assertArrayNotHasKey('.hidden', $entries);
        $this->assertArrayNotHasKey('notes.txt', $entries);
        $this->assertArrayNotHasKey('not-a-video', $entries);
    }

    public function testFlagsDirectoriesTheWebServerCannotRead(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root can read everything');
        }
        $this->hls('locked', true);
        chmod("$this->root/locked/720p", 0000);
        $this->assertSame('unreadable', $this->entries()['locked']['problem']);
    }

    public function testExcludesRegisteredPaths(): void
    {
        $entries = $this->entries(['clip', 'complete']);
        $this->assertArrayNotHasKey('clip', $entries);
        $this->assertArrayNotHasKey('complete', $entries);
        $this->assertNotNull((new MediaLibrary($this->root))->findUnregistered('legacy', ['clip']));
        $this->assertNull((new MediaLibrary($this->root))->findUnregistered('clip', ['clip']));
    }

    public function testLocatesEachLayout(): void
    {
        $library = new MediaLibrary($this->root, 'media/');
        $this->assertSame(['media/clip.mp4', 'clip.mp4', 'mp4'], array_values(array_diff_key($library->locate('clip'), ['file' => 1])));
        $this->assertSame('media/complete/master.m3u8', $library->locate('complete')['url']);
        $this->assertSame('media/legacy/manifest/video.m3u8', $library->locate('legacy')['url']);
        $this->assertSame('media/With%20Space.mp4', $library->locate('With Space')['url']);
        $this->assertNull($library->locate('missing'));
    }

    public function testRefusesPathsOutsideTheLibrary(): void
    {
        $library = new MediaLibrary($this->root);
        foreach (['../etc/passwd', '..', '.hidden', 'a/b', '', "x\0y"] as $path) {
            $this->assertNull($library->locate($path), "locate('$path')");
            $this->assertFalse(MediaLibrary::isValidPath($path), "isValidPath('$path')");
        }
    }
}
