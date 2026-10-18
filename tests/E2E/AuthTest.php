<?php
namespace Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\E2ETestCase;

class AuthTest extends E2ETestCase
{
    public function testLoginPageStartsASessionWithCsrfToken(): void
    {
        $client = $this->client();
        $page = $client->get('login');
        $this->assertSame(200, $page->status);
        $this->assertContains('mytube_sid', $page->cookiesSet());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $page->csrfToken());
    }

    public function testLoginRequiresTheCsrfToken(): void
    {
        $user = $this->fx->user('csrf');
        $client = $this->client();
        $client->get('login');
        $response = $client->post('login', ['identifier' => $user['username'], 'password' => $user['password']]);
        $this->assertSame(400, $response->status);
    }

    public function testSuccessfulLoginByUsernameOrEmail(): void
    {
        $user = $this->fx->user('alice', ['name' => 'Alice Test']);
        $client = $this->client();
        $client->get('login');
        $before = $client->cookie('mytube_sid');

        $response = $client->login($user, return: $this->basePath() . 'account');
        $this->assertRedirect($response, 'account');
        $this->assertNotSame($before, $client->cookie('mytube_sid'), 'session id regenerated at login');
        $this->assertSame(['Alice Test'], $client->get('account')->texts('user-name'));

        $byEmail = $this->client()->login(['username' => $user['email'], 'password' => $user['password']]);
        $this->assertRedirect($byEmail, '');
        $this->assertNotNull($this->fx->scalar('SELECT last_login_at FROM users WHERE id = ?', [$user['id']]));
    }

    public function testFailedLogins(): void
    {
        $user = $this->fx->user('bob');
        $wrong = $this->client()->login(['username' => $user['username'], 'password' => 'wrong-password']);
        $this->assertSame(401, $wrong->status);
        $this->assertAlert($wrong, 'Identifiant ou mot de passe incorrect');

        $unknown = $this->client()->login(['username' => 'zz_nobody', 'password' => 'whatever-password']);
        $this->assertSame(401, $unknown->status, 'same answer for unknown accounts');

        $unverified = $this->fx->user('unverified', ['verified' => false]);
        $response = $this->client()->login($unverified);
        $this->assertSame(403, $response->status);
        $this->assertAlert($response, 'pas encore été vérifiée');
        $this->assertStringContainsString('href="resend-verification"', $response->body);

        $disabled = $this->fx->user('disabled', ['active' => false]);
        $this->assertAlert($this->client()->login($disabled), 'désactivé');
    }

    public function testLoginIsThrottledAfterFiveFailures(): void
    {
        $user = $this->fx->user('throttled');
        $client = $this->client();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $client->login(['username' => $user['username'], 'password' => "bad-$i"])->status);
        }
        $blocked = $client->login($user);
        $this->assertSame(429, $blocked->status, 'even the right password is refused while throttled');
        $this->assertAlert($blocked, 'Trop de tentatives');
    }

    public static function maliciousReturns(): array
    {
        return [['https://evil.example/'], ['//evil.example/'], ['/videos/../../evil'], ['/other/path'], ['javascript:alert(1)']];
    }

    #[DataProvider('maliciousReturns')]
    public function testNoOpenRedirectAfterLogin(string $target): void
    {
        $user = $this->fx->user('redirect');
        if ($target === '/videos/../../evil') {
            $target = $this->basePath() . '../../evil';
        }
        $this->assertRedirect($this->client()->login($user, return: $target), '');
    }

    public function testLoggedInUserIsSentAwayFromTheLoginPage(): void
    {
        $client = $this->loggedIn($this->fx->user('already'));
        $this->assertRedirect($client->get('login?return=' . urlencode($this->basePath() . 'account')), 'account');
    }

    public function testRememberMeLogsBackInAndRotatesTheToken(): void
    {
        $user = $this->fx->user('remember');
        $client = $this->loggedIn($user, remember: true);
        $first = $client->cookie('mytube_remember');
        $this->assertNotEmpty($first);

        $client->removeCookie('mytube_sid');  // browser restarted
        $this->assertSame(200, $client->get('account')->status);
        $second = $client->cookie('mytube_remember');
        $this->assertNotSame($first, $second, 'token rotated on use');

        // Replaying the old cookie means it was stolen: every persistent login is revoked
        $thief = $this->client();
        $thief->setCookie('mytube_remember', $first);
        $this->assertRedirect($thief->get('account'), 'login?return=' . urlencode($this->basePath() . 'account'));
        $victim = $this->client();
        $victim->setCookie('mytube_remember', $second);
        $this->assertSame(302, $victim->get('account')->status, 'the legitimate cookie is revoked too');
        $this->assertSame(0, (int) $this->fx->scalar("SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND type = 'remember'", [$user['id']]));
    }

    public function testLogout(): void
    {
        $user = $this->fx->user('leaving');
        $client = $this->loggedIn($user, remember: true);
        $this->assertSame(400, $client->post('logout')->status, 'CSRF token required');
        $this->assertRedirect($client->logout(), '');
        $this->assertSame(302, $client->get('account')->status);
        $this->assertSame(0, (int) $this->fx->scalar("SELECT COUNT(*) FROM user_tokens WHERE user_id = ?", [$user['id']]));
    }

    public function testPasswordChangeElsewhereEndsTheSession(): void
    {
        $user = $this->fx->user('changed');
        $client = $this->loggedIn($user, remember: true);
        $this->fx->db()->prepare('UPDATE users SET password_changed_at = NOW(6) WHERE id = ?')->execute([$user['id']]);
        $this->assertSame(302, $client->get('account')->status, 'session refused');
        $client->removeCookie('mytube_sid');
        $this->assertSame(302, $client->get('account')->status, 'remember-me cookie refused');
    }

    public function testDisablingAnAccountEndsItsSessions(): void
    {
        $user = $this->fx->user('banned');
        $client = $this->loggedIn($user);
        $this->fx->db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$user['id']]);
        $this->assertSame(302, $client->get('account')->status);
    }
}
