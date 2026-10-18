<?php
namespace Tests\E2E;

use Tests\Support\E2ETestCase;
use Tests\Support\Fixtures;

/** Administration: media scan, adding, editing and removing videos */
class AdminVideosTest extends E2ETestCase
{
    private const MEDIA = MYTUBE_ROOT . '/media/';

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['zz_admin_clip.mp4', 'zz_sample.mkv', 'zz_UPPER.MP4'] as $file) {
            @unlink(self::MEDIA . $file);
        }
        exec('rm -rf ' . escapeshellarg(self::MEDIA . 'zz_encoding'));
    }

    private function admin(): \Tests\Support\HttpClient
    {
        return $this->loggedIn($this->fx->user('admin', ['admin' => true]));
    }

    public function testAdminPagesAreForAdminsOnly(): void
    {
        $routes = ['admin', 'admin/videos', 'admin/media', 'admin/videos/new?path=x', 'admin/videos/edit?id=x',
            'admin/collections', 'admin/collections/new', 'admin/groups', 'admin/groups/new', 'admin/users', 'admin/users/new'];
        $anonymous = $this->client();
        $member = $this->loggedIn($this->fx->user('member'));
        foreach ($routes as $route) {
            $this->assertSame(302, $anonymous->get($route)->status, "anonymous $route");
            $this->assertSame(404, $member->get($route)->status, "non-admin $route");
        }
        $this->assertSame(404, $member->submit('account', 'admin/videos/delete', ['id' => 'x'])->status);
        $this->assertRedirect($this->admin()->get('admin'), 'admin/videos');
    }

    public function testMediaScanFlagsProblems(): void
    {
        $this->fx->ensureClip();
        copy(self::MEDIA . Fixtures::CLIP . '.mp4', self::MEDIA . 'zz_admin_clip.mp4');
        touch(self::MEDIA . 'zz_sample.mkv');
        touch(self::MEDIA . 'zz_UPPER.MP4');
        mkdir(self::MEDIA . 'zz_encoding');
        file_put_contents(self::MEDIA . 'zz_encoding/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nv.m3u8\n");
        file_put_contents(self::MEDIA . 'zz_encoding/v.m3u8', "#EXTM3U\n#EXTINF:4,\nseg.ts\n");

        $page = $this->admin()->get('admin/media')->body;
        $this->assertStringContainsString('admin/videos/new?path=zz_admin_clip', $page);
        $this->assertStringContainsString('admin/videos/new?path=' . Fixtures::CLIP, $page, 'the unregistered test clip');
        foreach (['zz_sample.mkv' => 'Format non pris en charge', 'zz_UPPER.MP4' => 'Extension', 'zz_encoding' => 'Incomplet'] as $name => $badge) {
            $this->assertMatchesRegularExpression('#<code>' . preg_quote($name, '#') . '</code>.{0,600}' . preg_quote($badge, '#') . '#s', $page, $name);
        }
        $this->assertStringNotContainsString('new?path=zz_sample.mkv', $page, 'problems cannot be added');
    }

    public function testAddEditAndRemoveAVideo(): void
    {
        $this->fx->ensureClip();
        copy(self::MEDIA . Fixtures::CLIP . '.mp4', self::MEDIA . 'zz_admin_clip.mp4');
        $client = $this->admin();

        $form = $client->get('admin/videos/new?path=zz_admin_clip');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('value="zz admin clip"', $form->body, 'title suggested from the file name');
        $this->assertStringContainsString('320 × 240', $form->body, 'probed resolution');
        $this->assertStringContainsString('value="private" checked', $form->body, 'private by default');

        $this->assertSame(422, $client->submit('admin/videos/new?path=zz_admin_clip', 'admin/videos/new',
            ['path' => 'zz_admin_clip', 'title' => '', 'collection_id' => '0', 'visibility' => 'private'])->status);
        $created = $client->submit('admin/videos/new?path=zz_admin_clip', 'admin/videos/new', [
            'path' => 'zz_admin_clip', 'title' => 'ZZ Ajoutée', 'description' => 'Par le test', 'collection_id' => '0',
            'new_collection' => 'zz Nouvelle', 'visibility' => 'unlisted', 'thumbnail_time' => '1',
        ]);
        $this->assertSame(302, $created->status);
        $row = $this->fx->db()->query("SELECT v.*, c.name AS collection FROM videos v JOIN collections c ON c.id = v.collection_id
            WHERE v.path = 'zz_admin_clip'")->fetch(\PDO::FETCH_ASSOC);
        // The registered id doesn't start with "zz" (Fixtures::cleanup() also catches it by its media path)
        try {
            $this->assertSame(['ZZ Ajoutée', 'unlisted', '320x240', 'zz Nouvelle'], [$row['title'], $row['visibility'], $row['resolution'], $row['collection']]);
            $this->assertEqualsWithDelta(4.0, (float) $row['duration'], 0.2);
            $this->assertFileExists(MYTUBE_ROOT . "/thumbs/{$row['id']}.jpg");
            $this->assertStringContainsString("admin/videos/edit?id={$row['id']}", $created->location());
            $this->assertSame(302, $client->get('admin/videos/new?path=zz_admin_clip')->status, 'already registered');
            $edited = $client->submit("admin/videos/edit?id={$row['id']}", 'admin/videos/edit', [
                'id' => $row['id'], 'title' => 'ZZ Modifiée', 'description' => '', 'collection_id' => '0', 'visibility' => 'public', 'thumbnail_time' => '2',
            ]);
            $this->assertSame(302, $edited->status);
            $this->assertSame('public', $this->fx->scalar('SELECT visibility FROM videos WHERE id = ?', [$row['id']]));
            $this->assertStringContainsString('ZZ Modifiée', $client->get('admin/videos')->body);

            $deleted = $client->submit("admin/videos/edit?id={$row['id']}", 'admin/videos/delete', ['id' => $row['id']]);
            $this->assertRedirect($deleted, 'admin/videos');
            $this->assertFalse($this->fx->scalar('SELECT 1 FROM videos WHERE id = ?', [$row['id']]));
            $this->assertFileDoesNotExist(MYTUBE_ROOT . "/thumbs/{$row['id']}.jpg");
            $this->assertFileExists(self::MEDIA . 'zz_admin_clip.mp4', 'media files are kept');
        } finally {
            $this->fx->db()->prepare('DELETE FROM videos WHERE id = ?')->execute([$row['id']]);
            @unlink(MYTUBE_ROOT . "/thumbs/{$row['id']}.jpg");
        }
    }

    public function testCannotRegisterPathsOutsideTheLibrary(): void
    {
        $client = $this->admin();
        foreach (['..%2F.env', '.env', 'zz_does_not_exist'] as $path) {
            $this->assertRedirect($client->get("admin/videos/new?path=$path"), 'admin/media', $path);
        }
    }

    public function testVideoListShowsMediaStatusAndViews(): void
    {
        $missing = $this->fx->video(['path' => 'zz_gone', 'title' => 'ZZ sans média']);
        $this->fx->db()->prepare('UPDATE videos SET view_count = 1234 WHERE id = ?')->execute([$missing]);
        $page = $this->admin()->get('admin/videos')->body;
        $this->assertMatchesRegularExpression('#ZZ sans média.{0,800}1 234.{0,400}Introuvable#s', $page);
    }
}
