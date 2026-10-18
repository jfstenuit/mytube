<?php
namespace App\Core;

/** Per-session CSRF token, required on every POST */
class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }
        return $token;
    }

    public function isValid(?string $submitted): bool
    {
        $expected = $this->session->get(self::KEY);
        return is_string($submitted) && is_string($expected) && hash_equals($expected, $submitted);
    }

    /** Issue a new token (after login, so a token seen before login can't be reused) */
    public function reset(): void
    {
        $this->session->remove(self::KEY);
    }
}
