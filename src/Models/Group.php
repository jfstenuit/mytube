<?php
namespace App\Models;

use PDO;

/** User groups (user_groups), their members and collection grants, as associative arrays */
class Group
{
    /** Separator for GROUP_CONCAT lists (unit separator, can't appear in names typed in forms) */
    private const SEPARATOR = "\x1F";

    public static function all(PDO $db): array
    {
        $rows = $db->query('SELECT g.*,
                (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id) AS member_count,
                (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR \'' . self::SEPARATOR . '\') FROM group_collections gc
                    JOIN collections c ON c.id = gc.collection_id WHERE gc.group_id = g.id) AS collection_names
            FROM user_groups g ORDER BY g.is_builtin DESC, g.name')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['collections'] = $row['collection_names'] === null ? [] : explode(self::SEPARATOR, $row['collection_names']);
        }
        return $rows;
    }

    public static function find(PDO $db, int $id): ?array
    {
        $sth = $db->prepare('SELECT * FROM user_groups WHERE id = ?');
        $sth->execute([$id]);
        return $sth->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function nameExists(PDO $db, string $name, ?int $exceptId = null): bool
    {
        $sth = $db->prepare('SELECT 1 FROM user_groups WHERE name = ? AND id != ?');
        $sth->execute([$name, $exceptId ?? 0]);
        return (bool) $sth->fetchColumn();
    }

    public static function create(PDO $db, string $name, string $description): int
    {
        $db->prepare('INSERT INTO user_groups (name, description) VALUES (?, ?)')->execute([$name, $description]);
        return (int) $db->lastInsertId();
    }

    public static function update(PDO $db, int $id, string $name, string $description): void
    {
        $db->prepare('UPDATE user_groups SET name = ?, description = ? WHERE id = ? AND is_builtin = 0')
            ->execute([$name, $description, $id]);
    }

    /** Built-in groups can't be deleted; memberships and grants go with the group (cascade) */
    public static function delete(PDO $db, int $id): void
    {
        $db->prepare('DELETE FROM user_groups WHERE id = ? AND is_builtin = 0')->execute([$id]);
    }

    /** @return int[] */
    public static function memberIds(PDO $db, int $groupId): array
    {
        $sth = $db->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
        $sth->execute([$groupId]);
        return array_map('intval', $sth->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return int[] */
    public static function collectionIds(PDO $db, int $groupId): array
    {
        $sth = $db->prepare('SELECT collection_id FROM group_collections WHERE group_id = ?');
        $sth->execute([$groupId]);
        return array_map('intval', $sth->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return int[] groups of a user */
    public static function idsOfUser(PDO $db, int $userId): array
    {
        $sth = $db->prepare('SELECT group_id FROM group_members WHERE user_id = ?');
        $sth->execute([$userId]);
        return array_map('intval', $sth->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return int[] groups granted a collection */
    public static function idsOfCollection(PDO $db, int $collectionId): array
    {
        $sth = $db->prepare('SELECT group_id FROM group_collections WHERE collection_id = ?');
        $sth->execute([$collectionId]);
        return array_map('intval', $sth->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function setMembers(PDO $db, int $groupId, array $userIds): void
    {
        self::replaceLinks($db, 'group_members', 'group_id', $groupId, 'user_id', $userIds);
    }

    public static function setCollections(PDO $db, int $groupId, array $collectionIds): void
    {
        self::replaceLinks($db, 'group_collections', 'group_id', $groupId, 'collection_id', $collectionIds);
    }

    public static function setUserGroups(PDO $db, int $userId, array $groupIds): void
    {
        self::replaceLinks($db, 'group_members', 'user_id', $userId, 'group_id', $groupIds);
    }

    public static function setCollectionGroups(PDO $db, int $collectionId, array $groupIds): void
    {
        self::replaceLinks($db, 'group_collections', 'collection_id', $collectionId, 'group_id', $groupIds);
    }

    /**
     * Makes the link table contain exactly ($ownerColumn = $ownerId, $otherColumn ∈ $otherIds).
     * Table and column names are internal constants, never user input.
     */
    private static function replaceLinks(PDO $db, string $table, string $ownerColumn, int $ownerId,
                                         string $otherColumn, array $otherIds): void
    {
        $otherIds = array_values(array_unique(array_map('intval', $otherIds)));
        $db->beginTransaction();
        try {
            if ($otherIds) {
                $placeholders = implode(', ', array_fill(0, count($otherIds), '?'));
                $db->prepare("DELETE FROM $table WHERE $ownerColumn = ? AND $otherColumn NOT IN ($placeholders)")
                    ->execute(array_merge([$ownerId], $otherIds));
                $insert = $db->prepare("INSERT IGNORE INTO $table ($ownerColumn, $otherColumn) VALUES (?, ?)");
                foreach ($otherIds as $otherId) {
                    $insert->execute([$ownerId, $otherId]);
                }
            } else {
                $db->prepare("DELETE FROM $table WHERE $ownerColumn = ?")->execute([$ownerId]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
