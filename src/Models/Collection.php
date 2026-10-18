<?php
namespace App\Models;

use PDO;

/** Video collections, as associative arrays */
class Collection
{
    public static function all(PDO $db): array
    {
        return $db->query('SELECT c.id, c.name, COUNT(v.id) AS video_count FROM collections c
            LEFT JOIN videos v ON v.collection_id = c.id GROUP BY c.id, c.name ORDER BY c.name')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function exists(PDO $db, int $id): bool
    {
        $sth = $db->prepare('SELECT 1 FROM collections WHERE id = ?');
        $sth->execute([$id]);
        return (bool) $sth->fetchColumn();
    }

    /** Id of the collection with this name, created if needed */
    public static function findOrCreate(PDO $db, string $name): int
    {
        $sth = $db->prepare('SELECT id FROM collections WHERE name = ?');
        $sth->execute([$name]);
        $id = $sth->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $db->prepare('INSERT INTO collections (name) VALUES (?)')->execute([$name]);
        return (int) $db->lastInsertId();
    }
}
