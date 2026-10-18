<?php
namespace Tests\Support;

use App\Auth\Passwords;
use App\Core\Database;
use App\Models\User;
use PDO;

/**
 * Temporary test data in the development database. Everything created here is
 * prefixed with "zz" (users "zz_…", groups "zz_…", collections "zz …", video ids
 * "zz…", media "zz_…") and removed by cleanup().
 */
class Fixtures
{
    public const CLIP = 'zz_e2e_clip';
    private const CLIP_SECONDS = 4;

    private PDO $db;
    private string $startedAt;

    public function __construct()
    {
        $this->db = Database::get();
        $this->startedAt = $this->db->query('SELECT NOW() - INTERVAL 1 SECOND')->fetchColumn();
    }

    public function db(): PDO
    {
        return $this->db;
    }

    /**
     * @param array{admin?: bool, verified?: bool, active?: bool, password?: string|null, name?: string} $options
     * @return array{id: int, username: string, email: string, password: ?string}
     */
    public function user(string $name, array $options = []): array
    {
        $username = 'zz_' . $name;
        $password = array_key_exists('password', $options) ? $options['password'] : 'Test-Password-' . bin2hex(random_bytes(4));
        $id = User::create($this->db, $username, "$username@example.com", $options['name'] ?? ucfirst($name),
            $password === null ? null : Passwords::hash($password), $options['verified'] ?? true);
        if (!($options['active'] ?? true)) {
            $this->db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
        }
        if ($options['admin'] ?? false) {
            $this->db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)')
                ->execute([User::ADMIN_GROUP_ID, $id]);
        }
        return ['id' => $id, 'username' => $username, 'email' => "$username@example.com", 'password' => $password];
    }

    /** @param int[] $collectionIds @param int[] $memberIds */
    public function group(string $name, array $collectionIds = [], array $memberIds = []): int
    {
        $this->db->prepare('INSERT INTO user_groups (name, description) VALUES (?, ?)')->execute(['zz_' . $name, 'test']);
        $id = (int) $this->db->lastInsertId();
        foreach ($collectionIds as $collectionId) {
            $this->db->prepare('INSERT INTO group_collections (group_id, collection_id) VALUES (?, ?)')->execute([$id, $collectionId]);
        }
        foreach ($memberIds as $userId) {
            $this->db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)')->execute([$id, $userId]);
        }
        return $id;
    }

    public function collection(string $name): int
    {
        $this->db->prepare('INSERT INTO collections (name) VALUES (?)')->execute(['zz ' . $name]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Video row pointing to the generated test clip.
     * @param array{collection?: int, visibility?: string, title?: string, minutesAgo?: int, path?: string, duration?: float} $options
     */
    public function video(array $options = []): string
    {
        $this->ensureClip();
        $id = 'zz' . substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 10);
        $this->db->prepare('INSERT INTO videos (id, collection_id, visibility, title, path, resolution, duration, description, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() - INTERVAL ? MINUTE)')
            ->execute([$id, $options['collection'] ?? 0, $options['visibility'] ?? 'public', $options['title'] ?? "ZZ $id",
                $options['path'] ?? self::CLIP, '320x240', $options['duration'] ?? self::CLIP_SECONDS, '', $options['minutesAgo'] ?? 0]);
        return $id;
    }

    /** Short generated clip in media/ (ffmpeg test pattern), shared by the whole run */
    public function ensureClip(): string
    {
        $file = MYTUBE_ROOT . '/media/' . self::CLIP . '.mp4';
        if (!is_file($file)) {
            exec(sprintf('ffmpeg -v error -y -f lavfi -i testsrc=duration=%d:size=320x240:rate=25 -f lavfi -i sine=duration=%d '
                . '-c:v libx264 -pix_fmt yuv420p -c:a aac -shortest %s 2>&1', self::CLIP_SECONDS, self::CLIP_SECONDS,
                escapeshellarg($file)), $output, $status);
            if ($status !== 0) {
                throw new \RuntimeException('Could not generate the test clip: ' . implode("\n", $output));
            }
            chmod($file, 0644);
        }
        return self::CLIP;
    }

    public static function removeClip(): void
    {
        @unlink(MYTUBE_ROOT . '/media/' . self::CLIP . '.mp4');
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $sth = $this->db->prepare($sql);
        $sth->execute($params);
        return $sth->fetchColumn();
    }

    /** Removes every "zz" record, and throttle events recorded since this instance was created */
    public function cleanup(): void
    {
        $db = $this->db;
        // Test videos have a "zz" id, or (when added through the admin pages) "zz_" media
        $ids = $db->query("SELECT id FROM videos WHERE id LIKE 'zz%' OR path LIKE 'zz\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        $db->exec("DELETE FROM videos WHERE id LIKE 'zz%' OR path LIKE 'zz\\_%'");
        foreach ($ids as $id) {
            @unlink(MYTUBE_ROOT . "/thumbs/$id.jpg");
        }
        $db->exec("DELETE FROM users WHERE username LIKE 'zz\\_%'");
        $db->exec("DELETE FROM user_groups WHERE name LIKE 'zz\\_%' AND is_builtin = 0");
        $db->exec("DELETE FROM collections WHERE (name LIKE 'zz %' OR name LIKE 'zz\\_%')
            AND NOT EXISTS (SELECT 1 FROM videos v WHERE v.collection_id = collections.id)");
        $db->prepare('DELETE FROM throttle_events WHERE created_at >= ?')->execute([$this->startedAt]);
        foreach (glob(MYTUBE_ROOT . '/thumbs/zz*.jpg') ?: [] as $thumb) {
            @unlink($thumb);
        }
    }
}
