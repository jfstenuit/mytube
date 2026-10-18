<?php
namespace App\Controllers;

use App\Auth\Passwords;
use App\Core\App;
use App\Models\User;
use RuntimeException;

/** "Forgot password" request and password reset through an emailed link */
class PasswordResetController
{
    private const RESET_TTL = 3600;
    private const SESSION_KEY = 'reset_token';

    public function __construct(private App $app)
    {
    }

    public function forgot(): void
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
            if ($app->throttle->tooMany('reset', $email, $app->clientIp)) {
                [$status, $error] = [429, 'Trop de demandes. Veuillez réessayer plus tard.'];
            } elseif ($error = $app->recaptchaError('forgot_password')) {
                $status = 403;
            } else {
                $app->throttle->record('reset', $email, $app->clientIp, true);
                $user = str_contains($email, '@') ? User::findByIdentifier($app->db(), $email) : null;
                if ($user && $user->isActive) {
                    $app->tokens->deleteForUser($user->id, 'reset_password');
                    $token = $app->tokens->create($user->id, 'reset_password', self::RESET_TTL, null,
                        $request->userAgent(), $app->clientIp);
                    $this->mail($user, 'reset-password', [
                        'reset_url' => $app->publicUrl('reset-password?token=' . rawurlencode($token)),
                        'minutes' => self::RESET_TTL / 60,
                    ]);
                }
                $app->session->flash('info', sprintf(
                    'Si un compte correspond à l\'adresse %s, un e-mail contenant un lien de réinitialisation '
                    . 'vient d\'y être envoyé (valable %d minutes).', $email, self::RESET_TTL / 60));
                $app->redirect('login');
                return;
            }
        }

        $app->render('forgot-password.twig', ['email' => $email, 'error' => $error], $status);
    }

    public function reset(): void
    {
        $app = $this->app;
        $request = $app->request;
        header('Referrer-Policy: no-referrer');

        // Move the token from the URL into the session, so it doesn't stay in the
        // address bar, the browser history or Referer headers
        $urlToken = $request->query('token');
        if ($urlToken !== null) {
            $app->session->set(self::SESSION_KEY, $urlToken);
            $app->redirect('reset-password');
            return;
        }

        $row = $app->tokens->lookup('reset_password', (string) $app->session->get(self::SESSION_KEY, ''));
        $user = ($row && $row['validator_ok'] && !$row['expired']) ? User::find($app->db(), $row['user_id']) : null;
        if ($user === null || !$user->isActive) {
            $app->session->remove(self::SESSION_KEY);
            $app->render('link-invalid.twig', ['kind' => 'reset'], 400);
            return;
        }

        $error = null;
        if ($request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            $error = Passwords::validate($request->input('password', ''), $request->input('password_confirm', ''),
                [$user->username, $user->email]);
            if ($error === null) {
                $this->changePassword($user, $request->input('password'));
                return;
            }
        }

        $app->render('reset-password.twig', ['username' => $user->username, 'error' => $error], $error ? 422 : 200);
    }

    private function changePassword(User $user, string $password): void
    {
        $app = $this->app;
        // Following the emailed link also proves ownership of the address
        $app->db()->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(),
                email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')
            ->execute([Passwords::hash($password), $user->id]);
        // Revokes this link, other reset links and every persistent login
        $app->tokens->deleteForUser($user->id);
        $app->session->remove(self::SESSION_KEY);

        $this->mail($user, 'password-changed', ['forgot_url' => $app->publicUrl('forgot-password')]);

        $user = User::find($app->db(), $user->id);
        $app->auth->login($user, 'password_reset');
        $app->session->flash('success', 'Votre mot de passe a été modifié. Vous êtes connecté.');
        $app->redirect('');
    }

    private function mail(User $user, string $template, array $context): void
    {
        try {
            $this->app->mailer()->send($user->email, $user->displayName, $template,
                $context + ['name' => $user->displayName, 'username' => $user->username]);
        } catch (RuntimeException $e) {
            error_log("Could not send '$template' mail to {$user->email}: " . $e->getMessage());
        }
    }
}
