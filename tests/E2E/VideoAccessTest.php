<?php
namespace Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\E2ETestCase;
use Tests\Support\HttpClient;

/** Access rules on watch, embed, oembed, sidebar, collection switcher and home page */
class VideoAccessTest extends E2ETestCase
{
    private int $shared;
    private int $other;
    private array $v;
    private array $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shared = $this->fx->collection('Partage');
        $this->other = $this->fx->collection('Autre');
        $this->v = [
            'public' => $this->fx->video(['collection' => $this->shared, 'title' => 'ZZ public', 'minutesAgo' => 50]),
            'unlisted' => $this->fx->video(['collection' => $this->shared, 'visibility' => 'unlisted', 'title' => 'ZZ unlisted', 'minutesAgo' => 40]),
            'unlisted2' => $this->fx->video(['collection' => $this->shared, 'visibility' => 'unlisted', 'title' => 'ZZ unlisted 2', 'minutesAgo' => 30]),
            'private' => $this->fx->video(['collection' => $this->shared, 'visibility' => 'private', 'title' => 'ZZ private', 'minutesAgo' => 20]),
            'otherPublic' => $this->fx->video(['collection' => $this->other, 'title' => 'ZZ other public', 'minutesAgo' => 60]),
            'otherUnlisted' => $this->fx->video(['collection' => $this->other, 'visibility' => 'unlisted', 'title' => 'ZZ other unlisted', 'minutesAgo' => 5]),
        ];
        $member = $this->fx->user('member');
        $this->fx->group('partage', [$this->shared], [$member['id']]);
        $this->users = ['member' => $member, 'outsider' => $this->fx->user('outsider'), 'admin' => $this->fx->user('admin', ['admin' => true])];
    }

    private function viewer(string $who): HttpClient
    {
        return $who === 'anonymous' ? $this->client() : $this->loggedIn($this->users[$who]);
    }

    public static function matrix(): array
    {
        // viewer, video, [watch, embed, oembed]
        return [
            ['anonymous', 'public', [200, 200, 200]],
            ['anonymous', 'unlisted', [200, 200, 200]],
            ['anonymous', 'private', [302, 404, 401]],
            ['outsider', 'unlisted', [200, 200, 200]],
            ['outsider', 'private', [404, 404, 401]],
            ['member', 'private', [200, 200, 200]],
            ['admin', 'private', [200, 200, 200]],
        ];
    }

    #[DataProvider('matrix')]
    public function testAccessMatrix(string $who, string $video, array $expected): void
    {
        $client = $this->viewer($who);
        $id = $this->v[$video];
        $this->assertSame($expected, [
            $client->get("watch?v=$id")->status,
            $client->get("embed?v=$id")->status,
            $client->get("oembed?v=$id")->status,
        ]);
    }

    public function testAnonymousVisitorsOfPrivateVideosAreSentToLogin(): void
    {
        $id = $this->v['private'];
        $this->assertRedirect($this->client()->get("watch?v=$id"), 'login?return=' . urlencode($this->basePath() . "watch?v=$id"));
    }

    public function testSidebarListing(): void
    {
        $anonymous = $this->client();
        $this->assertSame([], $anonymous->get("watch?v={$this->v['public']}")->sidebarTitles(), 'public page: no unlisted siblings');
        $this->assertSame(['ZZ public', 'ZZ unlisted 2'], $anonymous->get("watch?v={$this->v['unlisted']}")->sidebarTitles(),
            'an unlisted link opens up its unlisted siblings');
        $this->assertSame(['ZZ public', 'ZZ unlisted', 'ZZ unlisted 2'], $this->viewer('member')->get("watch?v={$this->v['private']}")->sidebarTitles());
        $this->assertSame(['ZZ private', 'ZZ unlisted', 'ZZ unlisted 2'], $this->viewer('member')->get("watch?v={$this->v['public']}")->sidebarTitles());
    }

    public function testUnlistedAccessLeavesNoTrace(): void
    {
        $client = $this->client();
        $response = $client->get("watch?v={$this->v['unlisted']}");
        $this->assertSame([], $response->cookiesSet(), 'no cookie');
        $this->assertSame([], $client->get("watch?v={$this->v['public']}")->sidebarTitles(), 'nothing remembered');
    }

    public function testCollectionSwitcherLinksToTheLatestVisibleVideo(): void
    {
        $anonymous = $this->client()->get("watch?v={$this->v['otherPublic']}")->switcherLinks();
        $this->assertSame($this->v['public'], $anonymous['zz Partage'] ?? null, 'anonymous: latest public video');

        $member = $this->viewer('member')->get("watch?v={$this->v['otherPublic']}")->switcherLinks();
        $this->assertSame($this->v['private'], $member['zz Partage'] ?? null, 'member: latest video they may see');

        $admin = $this->viewer('admin')->get("watch?v={$this->v['public']}")->switcherLinks();
        $this->assertSame($this->v['otherUnlisted'], $admin['zz Autre'] ?? null);
        $this->assertArrayNotHasKey('zz Partage', $admin, 'the current collection is not listed');
    }

    public function testCollectionHeaderNamesTheCurrentCollection(): void
    {
        $page = $this->client()->get("watch?v={$this->v['public']}");
        $this->assertStringContainsString('<span class="collection-label">Collection</span>', $page->body);
        $this->assertStringContainsString('<h3>zz Partage</h3>', $page->body);
    }

    public function testHomePageForAMemberCanPickPrivateVideosOfTheirCollections(): void
    {
        $client = $this->viewer('member');
        $seen = [];
        for ($i = 0; $i < 25; $i++) {
            preg_match('#<title>([^<]+)</title>#', $client->get('')->body, $m);
            $seen[$m[1] ?? ''] = true;
        }
        $this->assertArrayNotHasKey('ZZ other unlisted', $seen, 'unlisted videos of other collections are never picked');
    }
}
