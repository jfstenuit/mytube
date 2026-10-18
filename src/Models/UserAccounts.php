<?php
namespace App\Models;

use PDO;

/** User accounts as seen by administrators (associative arrays) */
class UserAccounts
{
    /** Separator for GROUP_CONCAT lists (unit separator, can't appear in names typed in forms) */
    private const SEPARATOR = "\x1F";

    private const COLUMNS = 'u.id, u.username, u.email, u.display_name, u.is_active,
        u.email_verified_at IS NOT NULL AS email_verified, u.password_hash IS NOT NULL AS has_password,
        u.created_at, u.last_login_at';

    public static function all(PDO $db): array
    {
        $rows = $db->query('SELECT ' . self::COLUMNS . ',
                (SELECT GROUP_CONCAT(g.name ORDER BY g.is_builtin DESC, g.name SEPARATOR \'' . self::SEPARATOR . '\') FROM group_members m
                    JOIN user_groups g ON g.id = m.group_id WHERE m.user_id = u.id) AS group_names
            FROM users u ORDER BY u.username')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['groups'] = $row['group_names'] === null ? [] : explode(self::SEPARATOR, $row['group_names']);
        }
        return $rows;
    }

    /** id, username and display name of every user, for checkbox lists */
    public static function options(PDO $db): array
    {
        return $db->query('SELECT id, username, display_name, is_active FROM users ORDER BY username')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(PDO $db, int $id): ?array
    {
        $sth = $db->prepare('SELECT ' . self::COLUMNS . ' FROM users u WHERE u.id = ?');
        $sth->execute([$id]);
        return $sth->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function usernameExists(PDO $db, string $username, ?int $exceptId = null): bool
    {
        $sth = $db->prepare('SELECT 1 FROM users WHERE username = ? AND id != ?');
        $sth->execute([$username, $exceptId ?? 0]);
        return (bool) $sth->fetchColumn();
    }

    public static function emailExists(PDO $db, string $email, ?int $exceptId = null): bool
    {
        $sth = $db->prepare('SELECT 1 FROM users WHERE email = ? AND id != ?');
        $sth->execute([$email, $exceptId ?? 0]);
        return (bool) $sth->fetchColumn();
    }

    public static function update(PDO $db, int $id, array $data): void
    {
        $db->prepare('UPDATE users SET username = ?, display_name = ?, email = ?, is_active = ?,
                email_verified_at = IF(?, COALESCE(email_verified_at, NOW()), NULL) WHERE id = ?')
            ->execute([$data['username'], $data['display_name'], $data['email'], (int) $data['is_active'],
                (int) $data['email_verified'], $id]);
    }

    public static function delete(PDO $db, int $id): void
    {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    /** @return int[] ids of members of the admin group who can log in (active, email verified) */
    public static function activeAdminIds(PDO $db): array
    {
        $sth = $db->prepare('SELECT u.id FROM users u JOIN group_members m ON m.user_id = u.id
            WHERE m.group_id = ? AND u.is_active = 1 AND u.email_verified_at IS NOT NULL');
        $sth->execute([User::ADMIN_GROUP_ID]);
        return array_map('intval', $sth->fetchAll(PDO::FETCH_COLUMN));
    }
}
