<?php
namespace Tests\Integration;

use App\Models\Collection;
use App\Models\Group;
use App\Models\User;
use App\Models\UserAccounts;
use Tests\Support\IntegrationTestCase;

/** Groups, collections and accounts as managed by the administration pages */
class AdminModelsTest extends IntegrationTestCase
{
    public function testLinksAreReplacedFromEitherSide(): void
    {
        $db = $this->fx->db();
        $a = $this->fx->user('a')['id'];
        $b = $this->fx->user('b')['id'];
        $c1 = $this->fx->collection('one');
        $c2 = $this->fx->collection('two');
        $group = $this->fx->group('links');

        Group::setMembers($db, $group, [$a, $b, $b]);
        $this->assertEqualsCanonicalizing([$a, $b], Group::memberIds($db, $group));
        Group::setUserGroups($db, $b, []);
        $this->assertSame([$a], Group::memberIds($db, $group), 'removed from the user side');

        Group::setCollections($db, $group, [$c1]);
        Group::setCollectionGroups($db, $c2, [$group]);
        $this->assertEqualsCanonicalizing([$c1, $c2], Group::collectionIds($db, $group));
        Group::setCollectionGroups($db, $c1, []);
        $this->assertSame([$c2], Group::collectionIds($db, $group), 'revoked from the collection side');
        Group::setMembers($db, $group, []);
        $this->assertSame([], Group::memberIds($db, $group));
    }

    public function testListsCarryTheirLinkedNames(): void
    {
        $db = $this->fx->db();
        $user = $this->fx->user('listed');
        $collection = $this->fx->collection('Vacances, été');
        $this->fx->group('famille', [$collection], [$user['id']]);

        $groups = array_column(Group::all($db), null, 'name');
        $this->assertSame(['zz Vacances, été'], $groups['zz_famille']['collections'], 'names with commas stay whole');
        $this->assertSame(1, (int) $groups['zz_famille']['member_count']);
        $collections = array_column(Collection::all($db), null, 'name');
        $this->assertSame(['zz_famille'], $collections['zz Vacances, été']['groups']);
        $accounts = array_column(UserAccounts::all($db), null, 'username');
        $this->assertSame(['zz_famille'], $accounts['zz_listed']['groups']);
    }

    public function testBuiltInGroupCannotBeRenamedOrDeleted(): void
    {
        $db = $this->fx->db();
        Group::update($db, User::ADMIN_GROUP_ID, 'zz_hacked', 'x');
        Group::delete($db, User::ADMIN_GROUP_ID);
        $this->assertSame('admin', Group::find($db, User::ADMIN_GROUP_ID)['name']);
    }

    public function testNameChecks(): void
    {
        $db = $this->fx->db();
        $id = $this->fx->collection('unique');
        $this->assertTrue(Collection::nameExists($db, 'zz unique'));
        $this->assertFalse(Collection::nameExists($db, 'zz unique', $id), 'except itself');
        $this->assertSame($id, Collection::findOrCreate($db, 'zz unique'));
        $this->assertTrue(Group::nameExists($db, 'admin'));
        $this->assertTrue(UserAccounts::usernameExists($db, $this->fx->user('taken')['username']));
        $this->assertTrue(UserAccounts::emailExists($db, 'zz_taken@example.com'));
    }

    public function testActiveAdminsExcludeDisabledAndUnverifiedAccounts(): void
    {
        $db = $this->fx->db();
        $active = $this->fx->user('admin1', ['admin' => true])['id'];
        $disabled = $this->fx->user('admin2', ['admin' => true, 'active' => false])['id'];
        $unverified = $this->fx->user('admin3', ['admin' => true, 'verified' => false])['id'];
        $ids = UserAccounts::activeAdminIds($db);
        $this->assertContains($active, $ids);
        $this->assertNotContains($disabled, $ids);
        $this->assertNotContains($unverified, $ids);
    }
}
