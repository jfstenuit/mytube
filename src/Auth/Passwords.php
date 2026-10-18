<?php
namespace App\Auth;

/** Password hashing policy and validation rules */
class Passwords
{
    public const MIN_LENGTH = 10;
    private const MAX_BYTES = 1024;

    /** Argon2id when available (no 72-byte truncation like bcrypt), else PHP's default */
    public static function algorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    /**
     * @param string[] $forbidden values the password must not equal (username, email)
     * @return string|null error message, or null if the password is acceptable
     */
    public static function validate(string $password, string $confirmation, array $forbidden = []): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Le mot de passe doit contenir au moins ' . self::MIN_LENGTH . ' caractères.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'Le mot de passe est trop long.';
        }
        if ($password !== $confirmation) {
            return 'Les deux mots de passe ne correspondent pas.';
        }
        foreach ($forbidden as $value) {
            if ($value !== '' && mb_strtolower($password) === mb_strtolower($value)) {
                return 'Le mot de passe ne doit pas être identique à votre nom d\'utilisateur ou adresse e-mail.';
            }
        }
        return null;
    }
}
