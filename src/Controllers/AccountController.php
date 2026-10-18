<?php
namespace App\Controllers;

use App\Auth\Passwords;
use App\Core\App;
use App\Models\User;
use PDOException;
use RuntimeException;

/** "Mon compte": display name, email change (confirmed by email), password change */
class AccountController
{
    private const EMAIL_CHANGE_TTL = 24 * 3600;

    public function __construct(private App $app)
    {
    }

    public function account(): void
    {
        $app = $this->app;
        $user = $app->auth->user();
        if (!$user) {
            $app->redirect('login?return=' . urlencode($app->request->uri()));
            return;
        }

        $form = ['display_name' => $user->displayName, 'email' => $user->email];
        $profileErrors = [];
        $passwordError = null;
        $status = 200;

        if ($app->request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            if ($app->request->input('form') === 'password') {
                $passwordError = $this->changePassword($user);
            } else {
                [$form, $profileErrors] = $this->updateProfile($user);
            }
            if (!$profileErrors && $passwordError === null) {
                $app->redirect('account');
                return;
            }
            $status = 422;
        }

        $app->render('account.twig', [
            'form' => $form,
            'profile_errors' => $profileErrors,
            'password_error' => $passwordError,
            'has_password' => $user->hasPassword(),
            'pending_email' => $this->pendingEmail($user->id),
        ], $status);
    }

    /** Link from the confirmation email sent to the new address */
    public function confirmEmail(): void
    {
        $app = $this->app;
        header('Referrer-Policy: no-referrer');
        $row = $app->tokens->lookup('change_email', $app->request->query('token', ''));
        if (!$row || !$row['validator_ok'] || $row['expired'] || $row['data'] === null) {
            $app->render('link-invalid.twig', ['kind' => 'email'], 400);
            return;
        }

        $app->tokens->deleteForUser($row['user_id'], 'change_email');
        try {
            $app->db()->prepare('UPDATE users SET email = ?, email_verified_at = NOW() WHERE id = ?')
                ->execute([$row['data'], $row['user_id']]);
            $app->session->flash('success', sprintf('Votre adresse e-mail est maintenant %s.', $row['data']));
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // Registered by another account since the request
            $app->session->flash('error', 'Cette adresse e-mail est déjà utilisée par un autre compte.');
        }
        $app->redirect('account');
    }

    /** @return array{0: array, 1: string[]} [form values, errors] */
    private function updateProfile(User $user): array
    {
        $app = $this->app;
        $form = [
            'display_name' => trim($app->request->input('display_name', '')),
            'email' => trim($app->request->input('email', '')),
        ];
        $errors = [];
        if ($form['display_name'] === '' || mb_strlen($form['display_name']) > 255) {
            $errors[] = 'Le nom affiché est obligatoire (255 caractères au maximum).';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 255) {
            $errors[] = 'L\'adresse e-mail n\'est pas valide.';
        }
        $emailChanged = mb_strtolower($form['email']) !== mb_strtolower($user->email);
        $subject = 'user:' . $user->id;
        if (!$errors && $emailChanged && $app->throttle->tooMany('email_change', $subject, $app->clientIp)) {
            $errors[] = 'Trop de demandes de changement d\'adresse. Veuillez réessayer plus tard.';
        }
        if ($errors) {
            return [$form, $errors];
        }

        if ($form['display_name'] !== $user->displayName) {
            $app->db()->prepare('UPDATE users SET display_name = ? WHERE id = ?')
                ->execute([$form['display_name'], $user->id]);
            $app->session->flash('success', 'Votre profil a été mis à jour.');
        }

        if ($emailChanged) {
            $app->throttle->record('email_change', $subject, $app->clientIp, true);
            $this->requestEmailChange($user, $form['email'], $form['display_name']);
            // Same message whether or not the address belongs to another account
            $app->session->flash('info', sprintf(
                'Un lien de confirmation a été envoyé à %s (valable %d heures). '
                . 'Votre adresse ne sera modifiée qu\'après confirmation.',
                $form['email'], self::EMAIL_CHANGE_TTL / 3600));
        }
        return [$form, []];
    }

    private function requestEmailChange(User $user, string $newEmail, string $name): void
    {
        $app = $this->app;
        $app->tokens->deleteForUser($user->id, 'change_email');
        if (User::findByIdentifier($app->db(), $newEmail) !== null) {
            return;  // Taken: nothing is sent, so the form doesn't reveal other accounts
        }
        $token = $app->tokens->create($user->id, 'change_email', self::EMAIL_CHANGE_TTL, $newEmail,
            $app->request->userAgent(), $app->clientIp);
        $this->mail($newEmail, $name, 'confirm-email-change', [
            'name' => $name,
            'username' => $user->username,
            'confirm_url' => $app->publicUrl('confirm-email?token=' . rawurlencode($token)),
            'hours' => self::EMAIL_CHANGE_TTL / 3600,
        ]);
        // The current address is told, so a hijacked session can't take over the account unnoticed
        $this->mail($user->email, $name, 'email-change-requested', [
            'name' => $name,
            'username' => $user->username,
            'new_email' => $newEmail,
            'forgot_url' => $app->publicUrl('forgot-password'),
        ]);
    }

    /** @return string|null error message, or null on success */
    private function changePassword(User $user): ?string
    {
        $app = $this->app;
        $request = $app->request;
        $subject = 'user:' . $user->id;

        if ($user->hasPassword()) {
            if ($app->throttle->tooMany('password', $subject, $app->clientIp)) {
                return 'Trop de tentatives. Veuillez réessayer dans quelques minutes.';
            }
            if (!$user->verifyPassword($request->input('current_password', ''))) {
                $app->throttle->record('password', $subject, $app->clientIp, false);
                return 'Le mot de passe actuel est incorrect.';
            }
        }
        $error = Passwords::validate($request->input('new_password', ''), $request->input('confirm_password', ''),
            [$user->username, $user->email]);
        if ($error !== null) {
            return $error;
        }

        $app->db()->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?')
            ->execute([Passwords::hash($request->input('new_password')), $user->id]);
        $app->throttle->record('password', $subject, $app->clientIp, true);
        $app->auth->passwordChanged($user);

        $this->mail($user->email, $user->displayName, 'password-changed', [
            'name' => $user->displayName,
            'username' => $user->username,
            'forgot_url' => $app->publicUrl('forgot-password'),
        ]);
        $app->session->flash('success', 'Votre mot de passe a été modifié. Vos autres sessions ont été déconnectées.');
        return null;
    }

    private function pendingEmail(int $userId): ?string
    {
        $sth = $this->app->db()->prepare("SELECT data FROM user_tokens
            WHERE user_id = ? AND type = 'change_email' AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $sth->execute([$userId]);
        $email = $sth->fetchColumn();
        return $email === false ? null : $email;
    }

    private function mail(string $to, string $name, string $template, array $context): void
    {
        try {
            $this->app->mailer()->send($to, $name, $template, $context);
        } catch (RuntimeException $e) {
            error_log("Could not send '$template' mail to $to: " . $e->getMessage());
        }
    }
}
