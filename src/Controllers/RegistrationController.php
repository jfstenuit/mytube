<?php
namespace App\Controllers;

use App\Auth\Passwords;
use App\Core\App;
use App\Models\User;
use PDOException;
use RuntimeException;

/** Self-registration and email verification */
class RegistrationController
{
    private const VERIFY_TTL = 48 * 3600;
    private const UNVERIFIED_RETENTION_DAYS = 7;

    public function __construct(private App $app)
    {
    }

    public function register(): void
    {
        $app = $this->app;
        $request = $app->request;
        if ($app->auth->user()) {
            $app->redirect('');
            return;
        }

        $form = ['username' => '', 'display_name' => '', 'email' => ''];
        $errors = [];
        $status = 200;

        if ($request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            foreach ($form as $field => $_) {
                $form[$field] = trim($request->input($field, ''));
            }
            $password = $request->input('password', '');

            // Honeypot: invisible to humans; pretend success to bots that fill it
            if ($request->input('website', '') !== '') {
                $this->confirmationSent($form['email']);
                return;
            }

            if ($app->throttle->tooMany('register', $form['email'], $app->clientIp)) {
                $errors[] = 'Trop de demandes d\'inscription. Veuillez réessayer plus tard.';
                $status = 429;
            } elseif ($error = $app->recaptchaError('register')) {
                $errors[] = $error;
                $status = 403;
            } else {
                $errors = $this->validate($form, $password, $request->input('password_confirm', ''));
                $status = $errors ? 422 : 200;
            }

            if (!$errors) {
                $app->throttle->record('register', $form['email'], $app->clientIp, true);
                $this->purgeStaleAccounts();
                if (User::usernameExists($app->db(), $form['username'])) {
                    $errors[] = 'Ce nom d\'utilisateur est déjà pris.';
                    $status = 422;
                } else {
                    $this->createAccount($form, $password);
                    $this->confirmationSent($form['email']);
                    return;
                }
            }
        }

        $app->render('register.twig', ['form' => $form, 'errors' => $errors], $status);
    }

    public function verifyEmail(): void
    {
        $app = $this->app;
        header('Referrer-Policy: no-referrer');
        $row = $app->tokens->lookup('verify_email', $app->request->query('token', ''));
        if (!$row || !$row['validator_ok'] || $row['expired']) {
            $app->render('link-invalid.twig', ['kind' => 'verify'], 400);
            return;
        }

        $app->db()->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')
            ->execute([$row['user_id']]);
        $app->tokens->deleteForUser($row['user_id'], 'verify_email');

        $app->session->flash('success', 'Votre adresse e-mail est confirmée. Vous pouvez maintenant vous connecter.');
        $app->redirect('login');
    }

    public function resendVerification(): void
    {
        $app = $this->app;
        $request = $app->request;
        $email = '';
        $error = null;
        $status = 200;

        if ($request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            $email = trim($request->input('email', ''));
            if ($app->throttle->tooMany('resend', $email, $app->clientIp)) {
                [$status, $error] = [429, 'Trop de demandes. Veuillez réessayer plus tard.'];
            } elseif ($error = $app->recaptchaError('resend_verification')) {
                $status = 403;
            } else {
                $app->throttle->record('resend', $email, $app->clientIp, true);
                $user = str_contains($email, '@') ? User::findByIdentifier($app->db(), $email) : null;
                if ($user && $user->isActive && !$user->isEmailVerified) {
                    $this->sendVerification($user->id, $user->email, $user->displayName);
                }
                $this->confirmationSent($email);
                return;
            }
        }

        $app->render('resend-verification.twig', ['email' => $email, 'error' => $error], $status);
    }

    /** @return string[] validation errors */
    private function validate(array &$form, string $password, string $confirmation): array
    {
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $form['username'])) {
            $errors[] = 'Le nom d\'utilisateur doit contenir de 3 à 64 caractères : lettres, chiffres, « . », « _ » ou « - ».';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 255) {
            $errors[] = 'L\'adresse e-mail n\'est pas valide.';
        }
        if (mb_strlen($form['display_name']) > 255) {
            $errors[] = 'Le nom affiché est trop long.';
        }
        if ($form['display_name'] === '') {
            $form['display_name'] = $form['username'];
        }
        if ($error = Passwords::validate($password, $confirmation, [$form['username'], $form['email']])) {
            $errors[] = $error;
        }
        return $errors;
    }

    /**
     * Creates the account, or, if the email address is already registered, mails
     * its owner instead, so the form never reveals which addresses have an account.
     */
    private function createAccount(array $form, string $password): void
    {
        $db = $this->app->db();
        $existing = User::findByIdentifier($db, $form['email']);
        if ($existing === null) {
            try {
                $userId = User::create($db, $form['username'], $form['email'], $form['display_name'],
                    Passwords::hash($password));
                $this->sendVerification($userId, $form['email'], $form['display_name']);
                return;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // Registered concurrently: handled as an existing account below
                $existing = User::findByIdentifier($db, $form['email']);
                if ($existing === null) {
                    return;
                }
            }
        }

        if (!$existing->isEmailVerified) {
            $this->sendVerification($existing->id, $existing->email, $existing->displayName);
        } else {
            $this->mail($existing->email, $existing->displayName, 'account-exists', [
                'username' => $existing->username,
                'forgot_url' => $this->app->publicUrl('forgot-password'),
            ]);
        }
    }

    private function sendVerification(int $userId, string $email, string $name): void
    {
        $this->app->tokens->deleteForUser($userId, 'verify_email');
        $token = $this->app->tokens->create($userId, 'verify_email', self::VERIFY_TTL);
        $this->mail($email, $name, 'verify-email', [
            'name' => $name,
            'verify_url' => $this->app->publicUrl('verify-email?token=' . rawurlencode($token)),
            'hours' => self::VERIFY_TTL / 3600,
        ]);
    }

    private function mail(string $to, string $name, string $template, array $context): void
    {
        try {
            $this->app->mailer()->send($to, $name, $template, $context);
        } catch (RuntimeException $e) {
            // Not shown to the visitor (it would reveal whether the address exists)
            error_log("Could not send '$template' mail to $to: " . $e->getMessage());
        }
    }

    /** Same response whether or not an email was actually sent */
    private function confirmationSent(string $email): void
    {
        $this->app->session->flash('info', sprintf(
            'Si l\'adresse %s peut recevoir un compte, un e-mail de confirmation vient d\'y être envoyé. '
            . 'Cliquez sur le lien qu\'il contient pour activer votre compte (valable %d heures).',
            $email, self::VERIFY_TTL / 3600));
        $this->app->redirect('login');
    }

    /** Unverified accounts expire, so abandoned sign-ups don't hold usernames forever */
    private function purgeStaleAccounts(): void
    {
        $this->app->db()->exec('DELETE FROM users WHERE email_verified_at IS NULL
            AND created_at < NOW() - INTERVAL ' . self::UNVERIFIED_RETENTION_DAYS . ' DAY');
        $this->app->tokens->purgeExpired();
    }
}
