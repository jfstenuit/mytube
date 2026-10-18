<?php
namespace App\Models;

use App\Auth\Passwords;
use PDO;

class User
{
    /** Id of the built-in admin group (see migration 001) */
    public const ADMIN_GROUP_ID = 1;

    private const SELECT = 'SELECT id, username, email, display_name, password_hash, is_active,
            email_verified_at IS NOT NULL AS email_verified,
            COALESCE(UNIX_TIMESTAMP(password_changed_at), 0) AS password_changed_ts
        FROM users';

    /** @param array<int,string> $groups group id => group name */
    private function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $displayName,
        private readonly ?string $passwordHash,
        public readonly bool $isActive,
        public readonly bool $isEmailVerified,
        public readonly int $passwordChangedTs,
        public readonly array $groups
    ) {
    }

    public static function find(PDO $db, int $id): ?self
    {
        $sth = $db->prepare(self::SELECT . ' WHERE id = ?');
        $sth->execute([$id]);
        return self::hydrate($db, $sth->fetch(PDO::FETCH_ASSOC));
    }

    /** Lookup by email if the identifier contains '@' (usernames can't), else by username */
    public static function findByIdentifier(PDO $db, string $identifier): ?self
    {
        $column = str_contains($identifier, '@') ? 'email' : 'username';
        $sth = $db->prepare(self::SELECT . " WHERE $column = ?");
        $sth->execute([$identifier]);
        return self::hydrate($db, $sth->fetch(PDO::FETCH_ASSOC));
    }

    /** @return int id of the new user */
    public static function create(PDO $db, string $username, string $email, string $displayName,
                                  ?string $passwordHash, bool $emailVerified = false): int
    {
        $sth = $db->prepare('INSERT INTO users (username, email, email_verified_at, display_name, password_hash, password_changed_at)
            VALUES (?, ?, IF(?, NOW(), NULL), ?, ?, NOW())');
        $sth->execute([$username, $email, (int) $emailVerified, $displayName, $passwordHash]);
        return (int) $db->lastInsertId();
    }

    public static function usernameExists(PDO $db, string $username): bool
    {
        $sth = $db->prepare('SELECT 1 FROM users WHERE username = ?');
        $sth->execute([$username]);
        return (bool) $sth->fetchColumn();
    }

    private static function hydrate(PDO $db, array|false $row): ?self
    {
        if (!$row) {
            return null;
        }
        $sth = $db->prepare('SELECT g.id, g.name FROM group_members m
            JOIN user_groups g ON g.id = m.group_id WHERE m.user_id = ? ORDER BY g.name');
        $sth->execute([$row['id']]);

        return new self(
            (int) $row['id'],
            $row['username'],
            $row['email'],
            $row['display_name'] !== '' ? $row['display_name'] : $row['username'],
            $row['password_hash'],
            (bool) $row['is_active'],
            (bool) $row['email_verified'],
            (int) $row['password_changed_ts'],
            $sth->fetchAll(PDO::FETCH_KEY_PAIR)
        );
    }

    public function hasPassword(): bool
    {
        return $this->passwordHash !== null && $this->passwordHash !== '';
    }

    public function verifyPassword(string $password): bool
    {
        return $this->hasPassword() && password_verify($password, $this->passwordHash);
    }

    public function passwordNeedsRehash(): bool
    {
        return $this->hasPassword() && Passwords::needsRehash($this->passwordHash);
    }

    public function isAdmin(): bool
    {
        return isset($this->groups[self::ADMIN_GROUP_ID]);
    }

    public function canLogin(): bool
    {
        return $this->isActive && $this->isEmailVerified;
    }

    /** Data exposed to templates as "current_user" */
    public function toTemplate(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'display_name' => $this->displayName,
            'is_admin' => $this->isAdmin(),
            'groups' => array_values($this->groups),
        ];
    }
}
