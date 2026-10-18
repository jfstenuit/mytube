<?php
namespace Tests\E2E;

use Tests\Support\E2ETestCase;
use Tests\Support\HttpClient;
use Tests\Support\MailSink;

/** Self-registration and email verification (reCAPTCHA is disabled during the run) */
class RegistrationTest extends E2ETestCase
{
    private function register(HttpClient $client, string $name, array $overrides = []): \Tests\Support\Response
    {
        return $client->submit('register', 'register', $overrides + [
            'username' => "zz_$name",
            'display_name' => "Zoé O'$name",
            'email' => "zz_$name@example.com",
            'password' => 'Correct-Horse-42',
            'password_confirm' => 'Correct-Horse-42',
        ]);
    }

    public function testFullRegistrationAndVerification(): void
    {
        $client = $this->client();
        $response = $this->register($client, 'newcomer');
        $this->assertRedirect($response, 'login');
        $this->assertAlert($client->get('login'), 'un e-mail de confirmation vient d');

        $hash = $this->fx->scalar("SELECT password_hash FROM users WHERE username = 'zz_newcomer'");
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertNull($this->fx->scalar("SELECT email_verified_at FROM users WHERE username = 'zz_newcomer'"));

        $mails = $this->mailsTo('zz_newcomer@example.com');
        $this->assertCount(1, $mails);
        $this->assertSame('MyTube : confirmez votre adresse e-mail', $mails[0]['subject']);
        $this->assertStringContainsString("Bonjour Zoé O'newcomer,", $mails[0]['body'], 'plain text, not HTML-escaped');
        $link = MailSink::link($mails[0], $this->url('verify-email?token='));
        $this->assertNotNull($link, 'link built from SITE_URL');

        $unverified = $client->login(['username' => 'zz_newcomer', 'password' => 'Correct-Horse-42']);
        $this->assertSame(403, $unverified->status);

        $this->assertRedirect($client->get($link), 'login');
        $this->assertAlert($client->get('login'), 'adresse e-mail est confirmée');
        $this->assertSame(400, $client->get($link)->status, 'links work once');
        $this->assertRedirect($client->login(['username' => 'zz_newcomer', 'password' => 'Correct-Horse-42']), '');
    }

    public function testValidationErrors(): void
    {
        $response = $this->register($this->client(), 'x', [
            'username' => 'ab', 'email' => 'not-an-email', 'password' => 'short', 'password_confirm' => 'other',
        ]);
        $this->assertSame(422, $response->status);
        $alerts = implode(' ', $response->alerts());
        $this->assertStringContainsString('3 à 64 caractères', $alerts);
        $this->assertStringContainsString('adresse e-mail n\'est pas valide', $alerts);
        $this->assertStringContainsString('au moins 10 caractères', $alerts);
        $this->assertStringContainsString('value="ab"', $response->body, 'entered values are kept');
    }

    public function testHoneypotCreatesNothing(): void
    {
        $response = $this->register($this->client(), 'bot', ['website' => 'http://spam.example']);
        $this->assertRedirect($response, 'login', 'bots get the normal answer');
        $this->assertFalse($this->fx->scalar("SELECT 1 FROM users WHERE username = 'zz_bot'"));
        $this->assertSame([], $this->mailsTo('zz_bot@example.com'));
    }

    public function testTakenUsernameIsReported(): void
    {
        $this->fx->user('taken');
        $response = $this->register($this->client(), 'other', ['username' => 'ZZ_TAKEN']);
        $this->assertSame(422, $response->status);
        $this->assertAlert($response, 'déjà pris');
    }

    public function testExistingAddressesAreNotRevealed(): void
    {
        $existing = $this->fx->user('owner');
        $response = $this->register($this->client(), 'squatter', ['email' => $existing['email']]);
        $this->assertRedirect($response, 'login', 'same answer as a new address');
        $this->assertFalse($this->fx->scalar("SELECT 1 FROM users WHERE username = 'zz_squatter'"));
        $mails = $this->mailsTo($existing['email']);
        $this->assertCount(1, $mails);
        $this->assertStringContainsString("tentative d'inscription", $mails[0]['subject']);
        $this->assertStringContainsString('zz_owner', $mails[0]['body']);
        $this->assertNotNull(MailSink::link($mails[0], $this->url('forgot-password')));
    }

    public function testRegisteringAgainWithAnUnverifiedAddressResendsTheLink(): void
    {
        $client = $this->client();
        $this->register($client, 'twice');
        $this->register($client, 'twice2', ['email' => 'zz_twice@example.com']);
        $mails = $this->mailsTo('zz_twice@example.com');
        $this->assertCount(2, $mails);
        $this->assertSame('MyTube : confirmez votre adresse e-mail', $mails[1]['subject']);
        $this->assertSame(400, $client->get(MailSink::link($mails[0], $this->url('verify-email?token=')))->status,
            'the first link was replaced');
    }

    public function testResendVerification(): void
    {
        $unverified = $this->fx->user('pending', ['verified' => false]);
        $verified = $this->fx->user('done');
        $client = $this->client();

        $this->assertRedirect($client->submit('resend-verification', 'resend-verification', ['email' => $unverified['email']]), 'login');
        $this->assertCount(1, $this->mailsTo($unverified['email']));

        $client->submit('resend-verification', 'resend-verification', ['email' => $verified['email']]);
        $client->submit('resend-verification', 'resend-verification', ['email' => 'zz_unknown@example.com']);
        $this->assertSame([], $this->mailsTo($verified['email']));
        $this->assertSame([], $this->mailsTo('zz_unknown@example.com'));
    }

    public function testRegistrationIsThrottledPerAddress(): void
    {
        $client = $this->client();
        for ($i = 1; $i <= 3; $i++) {
            $this->register($client, "flood$i", ['email' => 'zz_flood@example.com']);
        }
        $this->assertSame(429, $this->register($client, 'flood4', ['email' => 'zz_flood@example.com'])->status);
    }

    public function testInvalidVerificationLink(): void
    {
        $response = $this->client()->get('verify-email?token=garbage');
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString('href="resend-verification"', $response->body);
    }

    public function testStaleUnverifiedSignUpsArePurged(): void
    {
        $stale = $this->fx->user('stale', ['verified' => false]);
        $invited = $this->fx->user('invited', ['verified' => false, 'password' => null]);
        $this->fx->db()->prepare('UPDATE users SET created_at = NOW() - INTERVAL 8 DAY WHERE id IN (?, ?)')
            ->execute([$stale['id'], $invited['id']]);
        $this->register($this->client(), 'trigger');  // purge runs on registration
        $this->assertFalse($this->fx->scalar('SELECT 1 FROM users WHERE id = ?', [$stale['id']]));
        $this->assertSame(1, (int) $this->fx->scalar('SELECT 1 FROM users WHERE id = ?', [$invited['id']]),
            'accounts invited by an admin are kept');
    }
}
