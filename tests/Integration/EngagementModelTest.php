<?php
namespace Tests\Integration;

use App\Models\Engagement;
use Tests\Support\IntegrationTestCase;

class EngagementModelTest extends IntegrationTestCase
{
    public function testLikesToggle(): void
    {
        $db = $this->fx->db();
        $video = $this->fx->video();
        $a = $this->fx->user('a')['id'];
        $b = $this->fx->user('b')['id'];

        $this->assertTrue(Engagement::toggleLike($db, $video, $a));
        $this->assertTrue(Engagement::toggleLike($db, $video, $b));
        $this->assertSame(2, Engagement::likeCount($db, $video));
        $this->assertFalse(Engagement::toggleLike($db, $video, $a));
        $this->assertFalse(Engagement::hasLiked($db, $video, $a));
        $this->assertTrue(Engagement::hasLiked($db, $video, $b));
        $this->assertSame(1, Engagement::likeCount($db, $video));
    }

    public function testCommentsNewestFirstWithAuthor(): void
    {
        $db = $this->fx->db();
        $video = $this->fx->video();
        $user = $this->fx->user('writer', ['name' => 'Zoé']);
        Engagement::addComment($db, $video, $user['id'], 'Premier');
        Engagement::addComment($db, $video, $user['id'], 'Second');

        $comments = Engagement::comments($db, $video);
        $this->assertSame(['Second', 'Premier'], array_column($comments, 'comment'));
        $this->assertSame('Zoé', $comments[0]['author']);

        Engagement::deleteComment($db, (int) $comments[0]['id']);
        $this->assertSame(['Premier'], array_column(Engagement::comments($db, $video), 'comment'));
    }

    public function testDeletingAVideoOrUserRemovesTheirEngagement(): void
    {
        $db = $this->fx->db();
        $video = $this->fx->video();
        $user = $this->fx->user('leaving')['id'];
        Engagement::toggleLike($db, $video, $user);
        Engagement::addComment($db, $video, $user, 'Bye');

        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$user]);
        $this->assertSame(0, Engagement::likeCount($db, $video));
        $this->assertSame([], Engagement::comments($db, $video));
    }
}
