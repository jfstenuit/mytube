<?php
namespace App\Core;

/**
 * PHP session wrapper. The session is started lazily: anonymous visitors who
 * never log in or submit a form don't get a session (nor a cookie), which
 * keeps public pages cacheable.
 */
class Session
{
    public const COOKIE_NAME = 'mytube_sid';
    private const IDLE_TIMEOUT = 7200;       // 2 hours without activity
    private const ABSOLUTE_TIMEOUT = 86400;  // 24 hours after creation

    private bool $started = false;

    public function __construct(
        private string $cookiePath,
        private bool $secure,
        private bool $hasCookie
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name(self::COOKIE_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;

        $now = time();
        if (isset($_SESSION['_created'])
            && ($now - ($_SESSION['_last'] ?? 0) > self::IDLE_TIMEOUT
                || $now - $_SESSION['_created'] > self::ABSOLUTE_TIMEOUT)) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_created'] ??= $now;
        $_SESSION['_last'] = $now;
    }

    /** Resume the session only if the browser sent a session cookie */
    public function resume(): void
    {
        if ($this->hasCookie) {
            $this->start();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->resume();
        return $this->started ? ($_SESSION[$key] ?? $default) : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->resume();
        if ($this->started) {
            unset($_SESSION[$key]);
        }
    }

    /** New session id, keeping the data (call on every privilege change) */
    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }

    public function destroy(): void
    {
        $this->resume();
        if (!$this->started) {
            return;
        }
        $_SESSION = [];
        session_destroy();
        setcookie(self::COOKIE_NAME, '', [
            'expires' => 1,
            'path' => $this->cookiePath,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $this->started = false;
        $this->hasCookie = false;
    }

    /** Message shown once, on the next rendered page */
    public function flash(string $type, string $message): void
    {
        $flashes = $this->get('_flash', []);
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $flashes);
    }

    public function takeFlashes(): array
    {
        $flashes = $this->get('_flash', []);
        if ($flashes) {
            $this->remove('_flash');
        }
        return $flashes;
    }
}
