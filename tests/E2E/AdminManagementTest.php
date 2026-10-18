<?php
namespace Tests\E2E;

use App\Models\User;
use Tests\Support\E2ETestCase;
use Tests\Support\HttpClient;
use Tests\Support\MailSink;

/** Administration of collections, groups and users, and the links between them */
class AdminManagementTest extends E2ETestCase
{
    private array $admin;
    private HttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->fx->user('admin', ['admin' => true]);
        $this->client = $this->loggedIn($this->admin);
    }

    private function idOf(string $table, string $column, string $value): int
    {
        return (int) $this->fx->scalar("SELECT id FROM $table WHERE $column = ?", [$value]);
    }

    public function testCollections(): void
    {
        $c = $this->client;
        $created = $c->submit('admin/collections/new', 'admin/collections/new', ['name' => 'zz Concerts']);
        $id = $this->idOf('collections', 'name', 'zz Concerts');
        $this->assertRedirect($created, "admin/collections/edit?id=$id");
        $this->assertSame(422, $c->submit('admin/collections/new', 'admin/collections/new', ['name' => 'zz Concerts'])->status, 'unique name');

        $c->submit("admin/collections/edit?id=$id", 'admin/collections/edit', ['id' => $id, 'name' => 'zz Concerts 2026']);
        $this->assertSame('zz Concerts 2026', $this->fx->scalar('SELECT name FROM collections WHERE id = ?', [$id]));

        $this->fx->video(['collection' => $id]);
        $c->submit("admin/collections/edit?id=$id", 'admin/collections/delete', ['id' => $id]);
        $this->assertAlert($c->get("admin/collections/edit?id=$id"), 'contient des vidéos');
        $this->fx->db()->exec("DELETE FROM videos WHERE id LIKE 'zz%'");
        $this->assertRedirect($c->submit("admin/collections/edit?id=$id", 'admin/collections/delete', ['id' => $id]), 'admin/collections');

        $c->submit('admin/collections/edit?id=0', 'admin/collections/delete', ['id' => '0']);
        $this->assertAlert($c->get('admin/collections/edit?id=0'), 'collection par défaut ne peut pas être supprimée');
    }

    public function testGroupsAndLinksFromBothSides(): void
    {
        $c = $this->client;
        $member = $this->fx->user('member');
        $collection = $this->fx->collection('Famille');
        $private = $this->fx->video(['collection' => $collection, 'visibility' => 'private']);

        $created = $c->submit('admin/groups/new', 'admin/groups/new', [
            'name' => 'zz_famille', 'description' => 'Test', 'members' => [$member['id']], 'collections' => [$collection, 999999],
        ]);
        $group = $this->idOf('user_groups', 'name', 'zz_famille');
        $this->assertRedirect($created, "admin/groups/edit?id=$group");
        $this->assertSame((string) $collection, (string) $this->fx->scalar('SELECT GROUP_CONCAT(collection_id) FROM group_collections WHERE group_id = ?', [$group]), 'unknown ids ignored');

        // Shown on the other sides
        $this->assertStringContainsString("name=\"groups[]\" value=\"$group\" checked", $c->get("admin/collections/edit?id=$collection")->body);
        $this->assertStringContainsString("name=\"groups[]\" value=\"$group\" checked", $c->get("admin/users/edit?id={$member['id']}")->body);

        $memberClient = $this->loggedIn($member);
        $this->assertSame(200, $memberClient->get("watch?v=$private")->status, 'the grant gives access');
        $c->submit("admin/collections/edit?id=$collection", 'admin/collections/edit', ['id' => $collection, 'name' => 'zz Famille']);
        $this->assertSame(404, $memberClient->get("watch?v=$private")->status, 'revoked from the collection page');

        $this->assertSame(422, $c->submit('admin/groups/new', 'admin/groups/new', ['name' => 'admin'])->status, 'unique name');
        $this->assertRedirect($c->submit("admin/groups/edit?id=$group", 'admin/groups/delete', ['id' => $group]), 'admin/groups');
        $this->assertSame(1, (int) $this->fx->scalar('SELECT COUNT(*) FROM users WHERE id = ?', [$member['id']]), 'members are kept');
    }

    public function testBuiltInAdminGroupIsProtected(): void
    {
        $c = $this->client;
        $admins = array_map('intval', $this->fx->db()->query('SELECT user_id FROM group_members WHERE group_id = 1')->fetchAll(\PDO::FETCH_COLUMN));

        $self = $c->submit('admin/groups/edit?id=1', 'admin/groups/edit', ['id' => '1', 'name' => 'x', 'members' => array_values(array_diff($admins, [$this->admin['id']]))]);
        $this->assertSame(422, $self->status);
        $this->assertAlert($self, 'vous retirer vous-même');

        $c->submit('admin/groups/edit?id=1', 'admin/groups/edit', ['id' => '1', 'name' => 'zz_hacked', 'members' => $admins, 'collections' => [0]]);
        $this->assertSame('admin', $this->fx->scalar('SELECT name FROM user_groups WHERE id = 1'), 'cannot be renamed');
        $this->assertSame(0, (int) $this->fx->scalar('SELECT COUNT(*) FROM group_collections WHERE group_id = 1'), 'no grants');
        $this->assertEqualsCanonicalizing($admins, array_map('intval',
            $this->fx->db()->query('SELECT user_id FROM group_members WHERE group_id = 1')->fetchAll(\PDO::FETCH_COLUMN)), 'members unchanged');

        $c->submit('admin/groups/edit?id=1', 'admin/groups/delete', ['id' => '1']);
        $this->assertAlert($c->get('admin/groups/edit?id=1'), 'ne peut pas être supprimé');
    }

    public function testInvitingAUser(): void
    {
        $c = $this->client;
        $group = $this->fx->group('invites');
        $this->assertSame(422, $c->submit('admin/users/new', 'admin/users/new', ['username' => 'a', 'email' => 'bad'])->status);
        $this->assertSame(422, $c->submit('admin/users/new', 'admin/users/new', ['username' => $this->admin['username'], 'email' => 'zz_x@example.com'])->status);

        $created = $c->submit('admin/users/new', 'admin/users/new', [
            'username' => 'zz_guest', 'display_name' => 'Invitée', 'email' => 'zz_guest@example.com', 'groups' => [$group],
        ]);
        $id = $this->idOf('users', 'username', 'zz_guest');
        $this->assertRedirect($created, "admin/users/edit?id=$id");
        $this->assertStringContainsString('Invitation en attente', $c->get('admin/users')->body);

        $mail = $this->mailsTo('zz_guest@example.com')[0];
        $this->assertSame('MyTube : votre compte a été créé', $mail['subject']);
        $this->assertStringContainsString('7 jours', $mail['body']);

        $guest = $this->client();
        $guest->get(MailSink::link($mail, $this->url('reset-password?token=')));
        $this->assertRedirect($guest->submit('reset-password', 'reset-password', ['password' => 'Guest-Secret-2026', 'password_confirm' => 'Guest-Secret-2026']), '');
        $this->assertSame(['Invitée'], $guest->get('account')->texts('user-name'));
        $this->assertNotNull($this->fx->scalar('SELECT email_verified_at FROM users WHERE id = ?', [$id]), 'the invitation verifies the address');
        $this->assertSame([$group], array_map('intval', $this->fx->db()->query("SELECT group_id FROM group_members WHERE user_id = $id")->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testEditingDisablingAndDeletingUsers(): void
    {
        $c = $this->client;
        $user = $this->fx->user('managed');
        $userClient = $this->loggedIn($user);
        $form = fn (array $changes) => $changes + ['id' => $user['id'], 'username' => $user['username'], 'display_name' => 'Managed',
            'email' => $user['email'], 'is_active' => '1', 'email_verified' => '1'];

        $taken = $c->submit("admin/users/edit?id={$user['id']}", 'admin/users/edit', $form(['email' => $this->admin['email']]));
        $this->assertAlert($taken, 'déjà utilisée');

        $c->submit("admin/users/edit?id={$user['id']}", 'admin/users/edit', $form(['is_active' => '0']));
        $this->assertSame(302, $userClient->get('account')->status, 'disabled: logged out at once');
        $this->assertAlert($this->client()->login($user), 'désactivé');

        $c->submit("admin/users/edit?id={$user['id']}", 'admin/users/edit', $form([]));
        $this->assertSame(302, $this->client()->login($user)->status, 're-enabled');

        $c->submit("admin/users/edit?id={$user['id']}", 'admin/users/send-link', ['id' => $user['id']]);
        $mail = $this->mailsTo($user['email'])[0];
        $this->assertStringContainsString('réinitialisation', $mail['subject']);
        $this->assertStringContainsString('24 heures', $mail['body']);

        $this->assertRedirect($c->submit("admin/users/edit?id={$user['id']}", 'admin/users/delete', ['id' => $user['id']]), 'admin/users');
        $this->assertFalse($this->fx->scalar('SELECT 1 FROM users WHERE id = ?', [$user['id']]));
    }

    public function testAdminsCannotLockThemselvesOut(): void
    {
        $c = $this->client;
        $self = $this->admin;
        $base = ['id' => $self['id'], 'username' => $self['username'], 'display_name' => 'Admin', 'email' => $self['email'],
            'is_active' => '1', 'email_verified' => '1', 'groups' => [User::ADMIN_GROUP_ID]];

        $this->assertAlert($c->submit("admin/users/edit?id={$self['id']}", 'admin/users/edit', ['is_active' => '0'] + $base), 'désactiver votre propre compte');
        $this->assertAlert($c->submit("admin/users/edit?id={$self['id']}", 'admin/users/edit', ['email_verified' => '0'] + $base), 'désactiver votre propre compte');
        $this->assertAlert($c->submit("admin/users/edit?id={$self['id']}", 'admin/users/edit', ['groups' => []] + $base), 'vous retirer vous-même');
        $c->submit("admin/users/edit?id={$self['id']}", 'admin/users/delete', ['id' => $self['id']]);
        $this->assertAlert($c->get("admin/users/edit?id={$self['id']}"), 'supprimer votre propre compte');
        $this->assertSame(200, $c->get('admin/users')->status, 'still an admin');
    }
}
