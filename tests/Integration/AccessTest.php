<?php
namespace Tests\Integration;

use App\Auth\Access;
use App\Models\User;
use App\Models\Video;
use PDO;
use Tests\Support\IntegrationTestCase;

/** Access rules (docs/auth-plan.md, "Access rules") on a fixture collection */
class AccessTest extends IntegrationTestCase
{
    private int $shared;
    private int $other;
    /** @var array<string, string> video ids by role */
    private array $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shared = $this->fx->collection('shared');
        $this->other = $this->fx->collection('other');
        $this->v = [
            'public' => $this->fx->video(['collection' => $this->shared, 'visibility' => 'public']),
            'unlisted' => $this->fx->video(['collection' => $this->shared, 'visibility' => 'unlisted']),
            'unlisted2' => $this->fx->video(['collection' => $this->shared, 'visibility' => 'unlisted']),
            'private' => $this->fx->video(['collection' => $this->shared, 'visibility' => 'private']),
            'otherPrivate' => $this->fx->video(['collection' => $this->other, 'visibility' => 'private']),
        ];
    }

    private function access(?array $user): Access
    {
        return new Access($this->fx->db(), $user ? User::find($this->fx->db(), $user['id']) : null);
    }

    /** Fixture video roles listed for this viewer */
    private function listed(Access $access, ?int $unlistedCollection = null): array
    {
        [$condition, $params] = $access->listableCondition('v', $unlistedCollection);
        $sth = $this->fx->db()->prepare("SELECT v.id FROM videos v WHERE v.id LIKE 'zz%' AND $condition");
        $sth->execute($params);
        $ids = $sth->fetchAll(PDO::FETCH_COLUMN);
        $roles = array_keys(array_filter($this->v, fn ($id) => in_array($id, $ids, true)));
        sort($roles);
        return $roles;
    }

    private function viewable(Access $access): array
    {
        $roles = array_keys(array_filter($this->v, fn ($id) => $access->canView(Video::find($this->fx->db(), $id))));
        sort($roles);
        return $roles;
    }

    public function testAnonymousVisitor(): void
    {
        $access = $this->access(null);
        $this->assertSame(['public', 'unlisted', 'unlisted2'], $this->viewable($access));
        $this->assertSame(['public'], $this->listed($access));
        $this->assertFalse($access->hasCollectionAccess($this->shared));
    }

    public function testUnlistedSiblingsAreListedOnAnUnlistedVideoPage(): void
    {
        $access = $this->access(null);
        $this->assertSame(['public', 'unlisted', 'unlisted2'], $this->listed($access, $this->shared));
        $this->assertSame(['public'], $this->listed($access, $this->other), 'only the given collection');
    }

    public function testLoggedInUserWithoutGroup(): void
    {
        $access = $this->access($this->fx->user('lonely'));
        $this->assertSame(['public', 'unlisted', 'unlisted2'], $this->viewable($access));
        $this->assertSame(['public'], $this->listed($access));
    }

    public function testGroupMemberSeesTheGrantedCollection(): void
    {
        $member = $this->fx->user('member');
        $this->fx->group('family', [$this->shared], [$member['id']]);
        $access = $this->access($member);
        $this->assertTrue($access->hasCollectionAccess($this->shared));
        $this->assertFalse($access->hasCollectionAccess($this->other));
        $this->assertSame(['private', 'public', 'unlisted', 'unlisted2'], $this->viewable($access));
        $this->assertSame(['private', 'public', 'unlisted', 'unlisted2'], $this->listed($access));
    }

    public function testAdminSeesEverything(): void
    {
        $access = $this->access($this->fx->user('boss', ['admin' => true]));
        $this->assertTrue($access->hasCollectionAccess($this->other));
        $this->assertSame(['otherPrivate', 'private', 'public', 'unlisted', 'unlisted2'], $this->viewable($access));
        $this->assertSame(['otherPrivate', 'private', 'public', 'unlisted', 'unlisted2'], $this->listed($access));
    }
}
