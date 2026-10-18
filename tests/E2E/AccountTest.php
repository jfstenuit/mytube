<?php
namespace Tests\E2E;

use Tests\Support\E2ETestCase;
use Tests\Support\MailSink;

class AccountTest extends E2ETestCase
{
    public function testRequiresLogin(): void
    {
        $this->assertRedirect($this->client()->get('account'), 'login?return=' . urlencode($this->basePath() . 'account'));
    }

    public function testDisplayNameChange(): void
    {
        $user = $this->fx->user('renamed');
        $client = $this->loggedIn($user);
        $this->assertSame(422, $client->submit('account', 'account', ['form' => 'profile', 'display_name' => '', 'email' => $user['email']])->status);
        $this->assertRedirect($client->submit('account', 'account', ['form' => 'profile', 'display_name' => "Zoé O'Neil", 'email' => $user['email']]), 'account');
        $page = $client->get('account');
        $this->assertAlert($page, 'profil a été mis à jour');
        $this->assertSame(["Zoé O'Neil"], $page->texts('user-name'));
    }

    public function testEmailChangeNeedsConfirmationFromTheNewAddress(): void
    {
        $user = $this->fx->user('mover');
        $client = $this->loggedIn($user);
        $client->submit('account', 'account', ['form' => 'profile', 'display_name' => 'Mover', 'email' => 'zz_moved@example.com']);

        $page = $client->get('account');
        $this->assertAlert($page, 'Un lien de confirmation a été envoyé à zz_moved@example.com');
        $this->assertStringContainsString('Changement en attente vers <strong>zz_moved@example.com</strong>', $page->body);
        $this->assertSame($user['email'], $this->fx->scalar('SELECT email FROM users WHERE id = ?', [$user['id']]), 'unchanged until confirmed');

        $notice = $this->mailsTo($user['email']);
        $this->assertCount(1, $notice, 'the current address is told');
        $this->assertStringContainsString('zz_moved@example.com', $notice[0]['body']);
        $link = MailSink::link($this->mailsTo('zz_moved@example.com')[0], $this->url('confirm-email?token='));

        $this->assertRedirect($this->client()->get($link), 'account', 'works from any browser');
        $this->assertSame('zz_moved@example.com', $this->fx->scalar('SELECT email FROM users WHERE id = ?', [$user['id']]));
        $this->assertSame(400, $this->client()->get($link)->status, 'links work once');
    }

    public function testTakenAddressSendsNothing(): void
    {
        $taken = $this->fx->user('taken');
        $client = $this->loggedIn($this->fx->user('curious'));
        $client->submit('account', 'account', ['form' => 'profile', 'display_name' => 'Curious', 'email' => $taken['email']]);
        $this->assertAlert($client->get('account'), 'Un lien de confirmation a été envoyé', 'same message as usual');
        $this->assertSame([], $this->mailsTo($taken['email']));
    }

    public function testEmailChangesAreThrottled(): void
    {
        $user = $this->fx->user('restless');
        $client = $this->loggedIn($user);
        for ($i = 1; $i <= 3; $i++) {
            $client->submit('account', 'account', ['form' => 'profile', 'display_name' => 'R', 'email' => "zz_new$i@example.com"]);
        }
        $response = $client->submit('account', 'account', ['form' => 'profile', 'display_name' => 'R', 'email' => 'zz_new4@example.com']);
        $this->assertSame(422, $response->status);
        $this->assertAlert($response, 'Trop de demandes');
    }

    public function testPasswordChangeKeepsThisSessionOnly(): void
    {
        $user = $this->fx->user('changer');
        $here = $this->loggedIn($user, remember: true);
        $rememberBefore = $here->cookie('mytube_remember');
        $elsewhere = $this->loggedIn($user);

        $wrong = $here->submit('account', 'account', ['form' => 'password', 'current_password' => 'wrong',
            'new_password' => 'New-Secret-2026', 'confirm_password' => 'New-Secret-2026']);
        $this->assertSame(422, $wrong->status);
        $this->assertAlert($wrong, 'mot de passe actuel est incorrect');

        $this->assertRedirect($here->submit('account', 'account', ['form' => 'password', 'current_password' => $user['password'],
            'new_password' => 'New-Secret-2026', 'confirm_password' => 'New-Secret-2026']), 'account');
        $this->assertSame(200, $here->get('account')->status, 'this session continues');
        $this->assertNotSame($rememberBefore, $here->cookie('mytube_remember'), 'its remember-me cookie is renewed');
        $this->assertSame(302, $elsewhere->get('account')->status, 'other sessions are logged out');
        $this->assertCount(1, $this->mailsTo($user['email']));

        $old = $this->client();
        $old->setCookie('mytube_remember', $rememberBefore);
        $this->assertSame(302, $old->get('account')->status, 'the previous remember-me cookie is revoked');
    }

    public function testCurrentPasswordChecksAreThrottled(): void
    {
        $user = $this->fx->user('guessed');
        $client = $this->loggedIn($user);
        for ($i = 0; $i < 5; $i++) {
            $client->submit('account', 'account', ['form' => 'password', 'current_password' => "bad$i",
                'new_password' => 'New-Secret-2026', 'confirm_password' => 'New-Secret-2026']);
        }
        $response = $client->submit('account', 'account', ['form' => 'password', 'current_password' => $user['password'],
            'new_password' => 'New-Secret-2026', 'confirm_password' => 'New-Secret-2026']);
        $this->assertAlert($response, 'Trop de tentatives');
    }

    public function testAccountWithoutPasswordCanSetOne(): void
    {
        $user = $this->fx->user('passkeyonly', ['password' => 'Temporary-2026']);
        $client = $this->loggedIn($user);
        $this->fx->db()->prepare('UPDATE users SET password_hash = NULL WHERE id = ?')->execute([$user['id']]);
        $page = $client->get('account');
        $this->assertStringContainsString('Définir un mot de passe', $page->body);
        $this->assertStringNotContainsString('name="current_password"', $page->body);
        $this->assertRedirect($client->submit('account', 'account', ['form' => 'password',
            'new_password' => 'Brand-New-2026', 'confirm_password' => 'Brand-New-2026']), 'account');
        $this->assertSame(302, $this->client()->login(['username' => $user['username'], 'password' => 'Brand-New-2026'])->status);
    }
}
