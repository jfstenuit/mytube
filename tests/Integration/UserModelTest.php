<?php
namespace Tests\Integration;

use App\Models\User;
use Tests\Support\IntegrationTestCase;

class UserModelTest extends IntegrationTestCase
{
    public function testFindsByUsernameOrEmail(): void
    {
        $alice = $this->fx->user('alice');
        $db = $this->fx->db();
        $this->assertSame($alice['id'], User::findByIdentifier($db, 'zz_alice')->id);
        $this->assertSame($alice['id'], User::findByIdentifier($db, 'ZZ_ALICE')->id, 'usernames are case-insensitive');
        $this->assertSame($alice['id'], User::findByIdentifier($db, 'zz_alice@example.com')->id);
        $this->assertNull(User::findByIdentifier($db, 'zz_nobody'));
        $this->assertSame('zz_alice', User::find($db, $alice['id'])->username);
    }

    public function testPasswordAndLoginAbility(): void
    {
        $db = $this->fx->db();
        $alice = User::find($db, $this->fx->user('alice', ['password' => 'Correct-Horse-42'])['id']);
        $this->assertTrue($alice->verifyPassword('Correct-Horse-42'));
        $this->assertFalse($alice->verifyPassword('wrong'));
        $this->assertTrue($alice->canLogin());

        $invited = User::find($db, $this->fx->user('invited', ['password' => null, 'verified' => false])['id']);
        $this->assertFalse($invited->hasPassword());
        $this->assertFalse($invited->verifyPassword(''));
        $this->assertFalse($invited->canLogin(), 'unverified accounts cannot log in');

        $this->assertFalse(User::find($db, $this->fx->user('disabled', ['active' => false])['id'])->canLogin());
    }

    public function testGroupsAndAdminFlag(): void
    {
        $db = $this->fx->db();
        $admin = $this->fx->user('boss', ['admin' => true]);
        $member = $this->fx->user('member');
        $this->fx->group('family', [], [$member['id'], $admin['id']]);

        $this->assertTrue(User::find($db, $admin['id'])->isAdmin());
        $this->assertFalse(User::find($db, $member['id'])->isAdmin());
        $this->assertSame(['zz_family'], array_values(User::find($db, $member['id'])->groups));
        $this->assertEqualsCanonicalizing(['admin', 'zz_family'], User::find($db, $admin['id'])->toTemplate()['groups']);
    }

    public function testDisplayNameFallsBackToUsername(): void
    {
        $user = $this->fx->user('noname', ['name' => '']);
        $this->assertSame('zz_noname', User::find($this->fx->db(), $user['id'])->displayName);
    }
}
