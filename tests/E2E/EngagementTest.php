<?php
namespace Tests\E2E;

use Tests\Support\E2ETestCase;
use Tests\Support\HttpClient;

/** View counting endpoint, likes and comments */
class EngagementTest extends E2ETestCase
{
    private int $collection;
    private string $video;
    private array $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collection = $this->fx->collection('Engagement');
        $this->video = $this->fx->video(['collection' => $this->collection, 'title' => 'ZZ Engagement']);  // 4 s: threshold 2 s
        $this->member = $this->fx->user('member', ['name' => 'Membre']);
        $this->fx->group('engagement', [$this->collection], [$this->member['id']]);
        // Each view test counts against this machine's hourly per-IP cap: start clean
        $this->fx->db()->exec("DELETE FROM throttle_events WHERE action = 'view'");
    }

    private function viewCount(): int
    {
        return (int) $this->fx->scalar('SELECT view_count FROM videos WHERE id = ?', [$this->video]);
    }

    /** Loads the watch page, waits the threshold, sends the beacon like views.js would */
    private function watchAndReport(HttpClient $client, bool $wait = true): void
    {
        $page = $client->get("watch?v={$this->video}");
        preg_match('/data-view-token="([^"]+)"/', $page->body, $token);
        $this->assertNotEmpty($token[1] ?? null, 'the watch page carries a view token');
        if ($wait) {
            sleep(3);
        }
        $this->assertSame(204, $client->post('view', ['v' => $this->video, 'token' => $token[1]])->status);
    }

    public function testViewsAreCountedOncePerViewerPerHour(): void
    {
        $page = $this->client()->get("watch?v={$this->video}");
        $this->assertStringContainsString('data-view-threshold="2"', $page->body);

        $client = $this->client();
        $this->watchAndReport($client);
        $this->assertSame(1, $this->viewCount());
        $this->watchAndReport($client);
        $this->assertSame(1, $this->viewCount(), 'same viewer within the hour');
        $this->watchAndReport($this->client(HttpClient::USER_AGENT . ' Other'));
        $this->assertSame(2, $this->viewCount(), 'another browser');
        $this->assertSame(['2 vues'], $client->get("watch?v={$this->video}")->texts('view-count'));
    }

    public function testRejectedReports(): void
    {
        $long = $this->fx->video(['collection' => $this->collection, 'duration' => 600]);  // threshold 30 s
        $client = $this->client();
        preg_match('/data-view-token="([^"]+)"/', $client->get("watch?v=$long")->body, $token);
        $client->post('view', ['v' => $long, 'token' => $token[1]]);
        $this->assertSame(0, (int) $this->fx->scalar('SELECT view_count FROM videos WHERE id = ?', [$long]), 'reported before the threshold');

        $this->watchAndReport($this->client('curl/8.14.1'));
        $this->assertSame(0, $this->viewCount(), 'bot user agent');
        $this->client()->post('view', ['v' => $this->video, 'token' => (time() - 60) . '.' . str_repeat('0', 64)]);
        $this->assertSame(0, $this->viewCount(), 'forged token');
    }

    public function testPrivateVideosOnlyCountForViewersWithAccess(): void
    {
        $this->fx->db()->prepare("UPDATE videos SET visibility = 'private' WHERE id = ?")->execute([$this->video]);
        $member = $this->loggedIn($this->member);
        $this->watchAndReport($member);
        $this->assertSame(1, $this->viewCount());
    }

    public function testEmbedPageReportsViewsToo(): void
    {
        $this->assertMatchesRegularExpression('/data-view-token="\d+\.[0-9a-f]{64}"/', $this->client()->get("embed?v={$this->video}")->body);
    }

    public function testLikePermissions(): void
    {
        $json = ['Accept: application/json'];
        $anonymous = $this->client();
        $page = $anonymous->get("watch?v={$this->video}");
        $this->assertStringContainsString('like-btn is-disabled', $page->body);
        $this->assertSame(400, $anonymous->post('like', ['v' => $this->video], $json)->status, 'no session, no CSRF token');

        $outsider = $this->loggedIn($this->fx->user('outsider'));
        $this->assertSame(403, $outsider->submit("watch?v={$this->video}", 'like', ['v' => $this->video], $json)->status);

        $member = $this->loggedIn($this->member);
        $liked = $member->submit("watch?v={$this->video}", 'like', ['v' => $this->video], $json);
        $this->assertSame(['liked' => true, 'count' => 1], $liked->json());
        $this->assertSame(['liked' => false, 'count' => 0], $member->submit("watch?v={$this->video}", 'like', ['v' => $this->video], $json)->json());

        $noJs = $member->submit("watch?v={$this->video}", 'like', ['v' => $this->video]);
        $this->assertRedirect($noJs, "watch?v={$this->video}");
        $this->assertStringContainsString('aria-pressed="true"', $member->get("watch?v={$this->video}")->body);

        $admin = $this->loggedIn($this->fx->user('admin', ['admin' => true]));
        $this->assertSame(200, $admin->submit("watch?v={$this->video}", 'like', ['v' => $this->video], $json)->status, 'admins may like');
    }

    public function testComments(): void
    {
        $outsider = $this->loggedIn($this->fx->user('outsider'));
        $this->assertStringContainsString('réservés aux membres', $outsider->get("watch?v={$this->video}")->body);
        $outsider->submit("watch?v={$this->video}", 'comment', ['v' => $this->video, 'comment' => 'Hello']);
        $this->assertAlert($outsider->get("watch?v={$this->video}"), 'Seuls les membres');

        $member = $this->loggedIn($this->member);
        $member->submit("watch?v={$this->video}", 'comment', ['v' => $this->video, 'comment' => '   ']);
        $this->assertAlert($member->get("watch?v={$this->video}"), 'vide');
        $member->submit("watch?v={$this->video}", 'comment', ['v' => $this->video, 'comment' => str_repeat('a', 2001)]);
        $this->assertStringContainsString(str_repeat('a', 2001) . '</textarea>', $member->get("watch?v={$this->video}")->body, 'draft kept');

        $posted = $member->submit("watch?v={$this->video}", 'comment', ['v' => $this->video, 'comment' => "Bravo !\n<b>pas du HTML</b>"]);
        $this->assertRedirect($posted, "watch?v={$this->video}#comments");
        $page = $this->client()->get("watch?v={$this->video}");
        $this->assertStringContainsString('1 commentaire', $page->body, 'visible to anonymous visitors');
        $this->assertStringContainsString('Bravo !<br />', $page->body);
        $this->assertStringContainsString('&lt;b&gt;pas du HTML&lt;/b&gt;', $page->body);
        $this->assertStringContainsString('<strong>Membre</strong>', $page->body);
    }

    public function testCommentDeletionRules(): void
    {
        $author = $this->loggedIn($this->member);
        $author->submit("watch?v={$this->video}", 'comment', ['v' => $this->video, 'comment' => 'À supprimer']);
        $id = (int) $this->fx->scalar('SELECT id FROM video_comments WHERE video_id = ?', [$this->video]);

        $other = $this->fx->user('other');
        $this->fx->db()->prepare('INSERT INTO group_members (group_id, user_id) SELECT group_id, ? FROM group_members WHERE user_id = ?')
            ->execute([$other['id'], $this->member['id']]);
        $otherClient = $this->loggedIn($other);
        $this->assertStringNotContainsString("name=\"id\" value=\"$id\"", $otherClient->get("watch?v={$this->video}")->body, 'no delete button');
        $this->assertSame(404, $otherClient->submit("watch?v={$this->video}", 'comment/delete', ['id' => $id])->status);

        $this->assertSame(302, $author->submit("watch?v={$this->video}", 'comment/delete', ['id' => $id])->status);
        $this->assertFalse($this->fx->scalar('SELECT 1 FROM video_comments WHERE id = ?', [$id]));

        $other2 = $this->loggedIn($other);
        $other2->submit("watch?v={$this->video}", 'comment', ['v' => $this->video, 'comment' => 'Modéré']);
        $id2 = (int) $this->fx->scalar('SELECT id FROM video_comments WHERE video_id = ?', [$this->video]);
        $admin = $this->loggedIn($this->fx->user('admin', ['admin' => true]));
        $this->assertSame(302, $admin->submit("watch?v={$this->video}", 'comment/delete', ['id' => $id2])->status, 'admins moderate');
        $this->assertFalse($this->fx->scalar('SELECT 1 FROM video_comments WHERE id = ?', [$id2]));
    }

    public function testCommentsAreThrottled(): void
    {
        $member = $this->loggedIn($this->member);
        for ($i = 1; $i <= 10; $i++) {
            $member->submit("watch?v={$this->video}", 'comment', ['v' => $this->video, 'comment' => "Commentaire $i"]);
        }
        $member->submit("watch?v={$this->video}", 'comment', ['v' => $this->video, 'comment' => 'Un de trop']);
        $this->assertAlert($member->get("watch?v={$this->video}"), 'beaucoup de commentaires');
        $this->assertSame(10, (int) $this->fx->scalar('SELECT COUNT(*) FROM video_comments WHERE video_id = ?', [$this->video]));
    }
}
