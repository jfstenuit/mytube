<?php
namespace Tests\E2E;

use Tests\Support\E2ETestCase;
use Tests\Support\MailSink;

class PasswordResetTest extends E2ETestCase
{
    public function testSameAnswerWhetherOrNotTheAccountExists(): void
    {
        $user = $this->fx->user('forgetful');
        $client = $this->client();
        $client->submit('forgot-password', 'forgot-password', ['email' => 'zz_nobody@example.com']);
        $unknown = $client->get('login')->alerts();
        $client->submit('forgot-password', 'forgot-password', ['email' => $user['email']]);
        $known = $client->get('login')->alerts();
        $this->assertSame(str_replace('zz_nobody@example.com', 'X', $unknown[0]), str_replace($user['email'], 'X', $known[0]));
        $this->assertSame([], $this->mailsTo('zz_nobody@example.com'));
        $this->assertCount(1, $this->mailsTo($user['email']));
    }

    public function testFullReset(): void
    {
        $user = $this->fx->user('reset');
        $other = $this->loggedIn($user, remember: true);  // another device, to be logged out
        $client = $this->client();
        $client->submit('forgot-password', 'forgot-password', ['email' => $user['email']]);
        $mail = $this->mailsTo($user['email'])[0];
        $this->assertStringContainsString('60 minutes', $mail['body']);
        $link = MailSink::link($mail, $this->url('reset-password?token='));

        $this->assertRedirect($client->get($link), 'reset-password', 'the token leaves the address bar');
        $form = $client->get('reset-password');
        $this->assertSame(200, $form->status);
        $this->assertSame('no-referrer', $form->header('referrer-policy'));

        $mismatch = $client->post('reset-password', ['csrf_token' => $form->csrfToken(), 'password' => 'New-Secret-2026', 'password_confirm' => 'nope']);
        $this->assertSame(422, $mismatch->status);

        $this->env->mail->clear();
        $done = $client->post('reset-password', ['csrf_token' => $form->csrfToken(), 'password' => 'New-Secret-2026', 'password_confirm' => 'New-Secret-2026']);
        $this->assertRedirect($done, '');
        $this->assertSame(200, $client->get('account')->status, 'logged in after the reset');
        $this->assertSame('MyTube : votre mot de passe a été modifié', $this->mailsTo($user['email'])[0]['subject']);

        $this->assertSame(302, $other->get('account')->status, 'other sessions are logged out');
        $this->assertSame(0, (int) $this->fx->scalar("SELECT COUNT(*) FROM user_tokens WHERE user_id = ?", [$user['id']]));
        $this->assertSame(401, $this->client()->login($user)->status, 'old password refused');
        $this->assertSame(302, $this->client()->login(['username' => $user['username'], 'password' => 'New-Secret-2026'])->status);

        $reuse = $this->client();
        $reuse->get($link);
        $this->assertSame(400, $reuse->get('reset-password')->status, 'links work once');
    }

    public function testResetAlsoVerifiesTheAddress(): void
    {
        $user = $this->fx->user('unverified', ['verified' => false]);
        $client = $this->client();
        $client->submit('forgot-password', 'forgot-password', ['email' => $user['email']]);
        $client->get(MailSink::link($this->mailsTo($user['email'])[0], $this->url('reset-password?token=')));
        $client->submit('reset-password', 'reset-password', ['password' => 'New-Secret-2026', 'password_confirm' => 'New-Secret-2026']);
        $this->assertNotNull($this->fx->scalar('SELECT email_verified_at FROM users WHERE id = ?', [$user['id']]));
    }

    public function testDisabledAccountsGetNoLink(): void
    {
        $user = $this->fx->user('disabled', ['active' => false]);
        $this->client()->submit('forgot-password', 'forgot-password', ['email' => $user['email']]);
        $this->assertSame([], $this->mailsTo($user['email']));
    }

    public function testInvalidLinks(): void
    {
        $client = $this->client();
        $this->assertRedirect($client->get('reset-password?token=' . str_repeat('A', 24) . ':forged'), 'reset-password');
        $this->assertSame(400, $client->get('reset-password')->status);
        $this->assertSame(400, $this->client()->get('reset-password')->status, 'no token at all');
    }

    public function testRequestsAreThrottled(): void
    {
        $user = $this->fx->user('spammed');
        $client = $this->client();
        for ($i = 0; $i < 3; $i++) {
            $client->submit('forgot-password', 'forgot-password', ['email' => $user['email']]);
        }
        $this->assertSame(429, $client->submit('forgot-password', 'forgot-password', ['email' => $user['email']])->status);
    }
}
