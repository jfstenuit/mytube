<?php
namespace App\Auth;

use PDO;

/**
 * Split tokens ("selector:validator") stored in user_tokens.
 *
 * The selector is a public lookup key; only a SHA-256 hash of the validator
 * is stored, so a database leak doesn't yield usable tokens, and validators
 * are compared in constant time.
 */
class TokenRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @return string the token to hand out ("selector:validator") */
    public function create(int $userId, string $type, int $ttl, ?string $data = null,
                           ?string $userAgent = null, ?string $ip = null): string
    {
        $selector = self::random(18);   // 24 base64url characters
        $validator = self::random(32);
        $sth = $this->db->prepare('INSERT INTO user_tokens
            (user_id, type, selector, validator_hash, data, expires_at, user_agent, ip)
            VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND, ?, ?)');
        $sth->execute([$userId, $type, $selector, hash('sha256', $validator), $data, $ttl,
                       $userAgent, $ip !== null ? inet_pton($ip) : null]);
        return "$selector:$validator";
    }

    /**
     * Find a token by its selector. Returns null if the format is invalid or
     * the selector is unknown; otherwise the row, with two computed flags:
     * - validator_ok: the validator matches (false may indicate a stolen token)
     * - expired
     */
    public function lookup(string $type, string $token): ?array
    {
        $parts = explode(':', $token, 2);
        if (count($parts) !== 2 || !preg_match('/^[A-Za-z0-9_-]{24}$/', $parts[0])) {
            return null;
        }
        [$selector, $validator] = $parts;
        $sth = $this->db->prepare('SELECT id, user_id, type, selector, validator_hash, data,
                UNIX_TIMESTAMP(created_at) AS created_ts, expires_at <= NOW() AS expired
            FROM user_tokens WHERE selector = ? AND type = ?');
        $sth->execute([$selector, $type]);
        $row = $sth->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['validator_ok'] = hash_equals($row['validator_hash'], hash('sha256', $validator));
        $row['expired'] = (bool) $row['expired'];
        $row['created_ts'] = (float) $row['created_ts'];  // microseconds (migration 005)
        return $row;
    }

    /** New validator for the same selector, extending the expiry; returns the new token */
    public function rotate(array $row, int $ttl): string
    {
        $validator = self::random(32);
        $sth = $this->db->prepare('UPDATE user_tokens
            SET validator_hash = ?, expires_at = NOW() + INTERVAL ? SECOND, last_used_at = NOW()
            WHERE id = ?');
        $sth->execute([hash('sha256', $validator), $ttl, $row['id']]);
        return $row['selector'] . ':' . $validator;
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM user_tokens WHERE id = ?')->execute([$id]);
    }

    public function deleteForUser(int $userId, ?string $type = null): void
    {
        if ($type === null) {
            $this->db->prepare('DELETE FROM user_tokens WHERE user_id = ?')->execute([$userId]);
        } else {
            $this->db->prepare('DELETE FROM user_tokens WHERE user_id = ? AND type = ?')->execute([$userId, $type]);
        }
    }

    public function purgeExpired(): void
    {
        $this->db->exec('DELETE FROM user_tokens WHERE expires_at <= NOW()');
    }

    private static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
