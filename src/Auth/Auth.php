<?php
namespace App\Auth;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Models\User;
use PDO;

/**
 * Authentication service.
 *
 * Every login method (password, remember-me cookie, later passkeys) ends in
 * login(), which owns the session state: user id, login time and method.
 */
class Auth
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const THROTTLED = 'throttled';
    public const INACTIVE = 'inactive';
    public const UNVERIFIED = 'unverified';

    public const REMEMBER_COOKIE = 'mytube_remember';
    private const REMEMBER_TTL = 30 * 86400;


    private ?User $user = null;
    private bool $resolved = false;

    public function __construct(
        private PDO $db,
        private Session $session,
        private Csrf $csrf,
        private TokenRepository $tokens,
        private Throttle $throttle,
        private Request $request,
        private string $clientIp,
        private string $cookiePath,
        private bool $secure
    ) {
    }

    /** The logged-in user, from the session or else from a remember-me cookie */
    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $userId = $this->session->get('auth.user_id');
        if ($userId !== null) {
            $user = User::find($this->db, $userId);
            // Sessions opened before the last password change are no longer valid
            if ($user && $user->canLogin() && $this->session->get('auth.login_at', 0) >= $user->passwordChangedTs) {
                return $this->user = $user;
            }
            $this->session->remove('auth.user_id');
        }

        $cookie = $this->request->cookie(self::REMEMBER_COOKIE);
        if ($cookie !== null) {
            $this->user = $this->loginFromRememberCookie($cookie);
        }
        return $this->user;
    }

    /** Password login; returns one of the result constants */
    public function attempt(string $identifier, string $password, bool $remember): string
    {
        $identifier = trim($identifier);
        if ($this->throttle->tooMany('login', $identifier, $this->clientIp)) {
            return self::THROTTLED;
        }

        $user = $identifier !== '' ? User::findByIdentifier($this->db, $identifier) : null;
        if ($user === null || !$user->hasPassword()) {
            // Same cost as a real verification, so timing doesn't reveal unknown accounts
            Passwords::hash($password);
            $this->throttle->record('login', $identifier, $this->clientIp, false);
            return self::INVALID;
        }
        if (!$user->verifyPassword($password)) {
            $this->throttle->record('login', $identifier, $this->clientIp, false);
            return self::INVALID;
        }
        // The password is correct from here on, so the account state can be revealed
        if (!$user->isActive) {
            return self::INACTIVE;
        }
        if (!$user->isEmailVerified) {
            return self::UNVERIFIED;
        }

        $this->throttle->record('login', $identifier, $this->clientIp, true);
        if ($user->passwordNeedsRehash()) {
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([Passwords::hash($password), $user->id]);
        }

        $this->login($user, 'password');
        if ($remember) {
            $this->setRememberCookie($this->tokens->create($user->id, 'remember', self::REMEMBER_TTL, null,
                $this->request->userAgent(), $this->clientIp));
        }
        return self::OK;
    }

    /** Open an authenticated session for $user; $method: password, remember, passkey… */
    public function login(User $user, string $method): void
    {
        $this->session->regenerate();
        $this->csrf->reset();
        $this->session->set('auth.user_id', $user->id);
        // Never earlier than the last password change (clock skew between PHP and the database)
        $this->session->set('auth.login_at', max(microtime(true), $user->passwordChangedTs));
        $this->session->set('auth.method', $method);
        $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user->id]);

        $this->user = $user;
        $this->resolved = true;
    }

    /**
     * After a password change by the user: every persistent login is revoked and other
     * sessions become invalid (password_changed_at); the current session continues, and
     * keeps its "remember me" if it had a valid one.
     */
    public function passwordChanged(User $user): void
    {
        $cookie = $this->request->cookie(self::REMEMBER_COOKIE);
        $row = $cookie !== null ? $this->tokens->lookup('remember', $cookie) : null;
        $keepRemember = $row && $row['validator_ok'] && !$row['expired'] && (int) $row['user_id'] === $user->id;

        $this->tokens->deleteForUser($user->id, 'remember');
        $this->login(User::find($this->db, $user->id), 'password');
        if ($keepRemember) {
            $this->setRememberCookie($this->tokens->create($user->id, 'remember', self::REMEMBER_TTL, null,
                $this->request->userAgent(), $this->clientIp));
        } elseif ($cookie !== null) {
            $this->clearRememberCookie();
        }
    }

    public function logout(): void
    {
        $cookie = $this->request->cookie(self::REMEMBER_COOKIE);
        if ($cookie !== null) {
            $row = $this->tokens->lookup('remember', $cookie);
            if ($row && $row['validator_ok']) {
                $this->tokens->delete($row['id']);
            }
            $this->clearRememberCookie();
        }
        $this->session->destroy();
        $this->user = null;
        $this->resolved = true;
    }

    private function loginFromRememberCookie(string $cookie): ?User
    {
        $row = $this->tokens->lookup('remember', $cookie);
        if ($row === null) {
            $this->clearRememberCookie();
            return null;
        }
        if (!$row['validator_ok']) {
            // Known selector with a wrong validator: the cookie was stolen and already
            // used by someone else (or vice versa). Revoke all persistent logins.
            error_log("Remember-me token theft suspected for user {$row['user_id']}; all remember tokens revoked");
            $this->tokens->deleteForUser($row['user_id'], 'remember');
            $this->clearRememberCookie();
            return null;
        }

        $user = $row['expired'] ? null : User::find($this->db, $row['user_id']);
        if ($user === null || !$user->canLogin() || $row['created_ts'] < $user->passwordChangedTs) {
            $this->tokens->delete($row['id']);
            $this->clearRememberCookie();
            return null;
        }

        $this->setRememberCookie($this->tokens->rotate($row, self::REMEMBER_TTL));
        $this->login($user, 'remember');
        return $user;
    }

    private function setRememberCookie(string $token): void
    {
        setcookie(self::REMEMBER_COOKIE, $token, $this->cookieOptions(time() + self::REMEMBER_TTL));
    }

    private function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', $this->cookieOptions(1));
    }

    private function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => $this->cookiePath,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
