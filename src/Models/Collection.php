<?php
namespace App\Models;

use PDO;

/** Video collections, as associative arrays */
class Collection
{
    /** Separator for GROUP_CONCAT lists (unit separator, can't appear in names typed in forms) */
    private const SEPARATOR = "\x1F";

    /** Default collection (id 0, see scripts/init-db.sql); it can't be deleted */
    public const DEFAULT_ID = 0;

    public static function all(PDO $db): array
    {
        $rows = $db->query('SELECT c.id, c.name, COUNT(v.id) AS video_count,
                (SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR \'' . self::SEPARATOR . '\') FROM group_collections gc
                    JOIN user_groups g ON g.id = gc.group_id WHERE gc.collection_id = c.id) AS group_names
            FROM collections c LEFT JOIN videos v ON v.collection_id = c.id
            GROUP BY c.id, c.name ORDER BY c.name')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['groups'] = $row['group_names'] === null ? [] : explode(self::SEPARATOR, $row['group_names']);
        }
        return $rows;
    }

    public static function find(PDO $db, int $id): ?array
    {
        $sth = $db->prepare('SELECT * FROM collections WHERE id = ?');
        $sth->execute([$id]);
        return $sth->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function exists(PDO $db, int $id): bool
    {
        return self::find($db, $id) !== null;
    }

    public static function nameExists(PDO $db, string $name, ?int $exceptId = null): bool
    {
        $sth = $db->prepare('SELECT 1 FROM collections WHERE name = ? AND id != ?');
        $sth->execute([$name, $exceptId ?? -1]);
        return (bool) $sth->fetchColumn();
    }

    /** Id of the collection with this name, created if needed */
    public static function findOrCreate(PDO $db, string $name): int
    {
        $sth = $db->prepare('SELECT id FROM collections WHERE name = ?');
        $sth->execute([$name]);
        $id = $sth->fetchColumn();
        return $id !== false ? (int) $id : self::create($db, $name);
    }

    public static function create(PDO $db, string $name): int
    {
        $db->prepare('INSERT INTO collections (name) VALUES (?)')->execute([$name]);
        return (int) $db->lastInsertId();
    }

    public static function rename(PDO $db, int $id, string $name): void
    {
        $db->prepare('UPDATE collections SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    /** Only empty collections can be deleted (videos reference them); grants go with it */
    public static function delete(PDO $db, int $id): void
    {
        $db->prepare('DELETE FROM collections WHERE id = ?')->execute([$id]);
    }

    public static function videos(PDO $db, int $id): array
    {
        $sth = $db->prepare('SELECT id, title, visibility FROM videos WHERE collection_id = ? ORDER BY title');
        $sth->execute([$id]);
        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }
}
