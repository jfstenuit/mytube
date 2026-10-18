<?php
namespace Tests\Integration;

use App\Media\VideoProbe;
use Tests\Support\IntegrationTestCase;

/** ffprobe/ffmpeg on the generated test clip */
class VideoProbeTest extends IntegrationTestCase
{
    public function testProbesResolutionAndDuration(): void
    {
        $file = MYTUBE_ROOT . '/media/' . $this->fx->ensureClip() . '.mp4';
        $info = VideoProbe::probe($file);
        $this->assertSame([320, 240], [$info['width'], $info['height']]);
        $this->assertEqualsWithDelta(4.0, $info['duration'], 0.2);
        $this->assertSame('320x240', VideoProbe::resolution($file));
    }

    public function testGeneratesAThumbnail(): void
    {
        $file = MYTUBE_ROOT . '/media/' . $this->fx->ensureClip() . '.mp4';
        $thumbnail = MYTUBE_ROOT . '/thumbs/zz_probe_test.jpg';
        $this->assertTrue(VideoProbe::generateThumbnail($file, $thumbnail, 1.0));
        $this->assertSame([320, 240], array_slice(getimagesize($thumbnail), 0, 2));
        $this->assertTrue(VideoProbe::generateThumbnail($file, $thumbnail, 2.0), 'overwrites an existing thumbnail');
    }

    public function testFailsGracefullyOnMissingFiles(): void
    {
        $this->expectErrorLog();
        $this->assertNull(VideoProbe::probe(MYTUBE_ROOT . '/media/zz_missing.mp4'));
    }
}
