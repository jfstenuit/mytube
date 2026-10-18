<?php
namespace App\Models;

use PDO;

/** Video metadata (videos table), as associative arrays */
class Video
{
    public const VISIBILITIES = ['public', 'unlisted', 'private'];

    public static function find(PDO $db, string $id): ?array
    {
        $sth = $db->prepare('SELECT * FROM videos WHERE id = ?');
        $sth->execute([$id]);
        return $sth->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** All videos with their collection name, by collection then title */
    public static function all(PDO $db): array
    {
        return $db->query('SELECT v.*, c.name AS collection_name FROM videos v
            JOIN collections c ON c.id = v.collection_id ORDER BY c.name, v.title')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return string[] */
    public static function registeredPaths(PDO $db): array
    {
        return $db->query('SELECT path FROM videos')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return string id of the new video */
    public static function create(PDO $db, array $data): string
    {
        $sth = $db->prepare('INSERT INTO videos (id, collection_id, visibility, title, path, resolution, description)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        // Retry on the (unlikely) collision of random ids
        for ($attempt = 0; ; $attempt++) {
            $id = self::generateId();
            try {
                $sth->execute([$id, $data['collection_id'], $data['visibility'], $data['title'], $data['path'],
                    $data['resolution'], $data['description']]);
                return $id;
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000' || $attempt >= 3 || self::find($db, $id) === null) {
                    throw $e;
                }
            }
        }
    }

    public static function update(PDO $db, string $id, array $data): void
    {
        $db->prepare('UPDATE videos SET collection_id = ?, visibility = ?, title = ?, description = ? WHERE id = ?')
            ->execute([$data['collection_id'], $data['visibility'], $data['title'], $data['description'], $id]);
    }

    public static function delete(PDO $db, string $id): void
    {
        $db->prepare('DELETE FROM video_likes WHERE video_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM video_comments WHERE video_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
    }

    /** 12-character base64url id, as used in watch?v=… (same format as scripts/add-video.sh) */
    public static function generateId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
    }
}
