<?php
namespace App\Models;

use PDO;

/** Likes and comments on videos */
class Engagement
{
    public const COMMENT_MAX_LENGTH = 2000;

    public static function likeCount(PDO $db, string $videoId): int
    {
        $sth = $db->prepare('SELECT COUNT(*) FROM video_likes WHERE video_id = ?');
        $sth->execute([$videoId]);
        return (int) $sth->fetchColumn();
    }

    public static function hasLiked(PDO $db, string $videoId, int $userId): bool
    {
        $sth = $db->prepare('SELECT 1 FROM video_likes WHERE video_id = ? AND user_id = ?');
        $sth->execute([$videoId, $userId]);
        return (bool) $sth->fetchColumn();
    }

    /** @return bool whether the video is liked after the toggle */
    public static function toggleLike(PDO $db, string $videoId, int $userId): bool
    {
        if (self::hasLiked($db, $videoId, $userId)) {
            $db->prepare('DELETE FROM video_likes WHERE video_id = ? AND user_id = ?')->execute([$videoId, $userId]);
            return false;
        }
        $db->prepare('INSERT IGNORE INTO video_likes (video_id, user_id) VALUES (?, ?)')->execute([$videoId, $userId]);
        return true;
    }

    /** Comments of a video, newest first, with their author's display name */
    public static function comments(PDO $db, string $videoId): array
    {
        $sth = $db->prepare('SELECT c.id, c.user_id, c.comment, c.posted_at,
                IF(u.display_name = \'\', u.username, u.display_name) AS author
            FROM video_comments c JOIN users u ON u.id = c.user_id
            WHERE c.video_id = ? ORDER BY c.posted_at DESC, c.id DESC');
        $sth->execute([$videoId]);
        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findComment(PDO $db, int $id): ?array
    {
        $sth = $db->prepare('SELECT * FROM video_comments WHERE id = ?');
        $sth->execute([$id]);
        return $sth->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function addComment(PDO $db, string $videoId, int $userId, string $comment): void
    {
        $db->prepare('INSERT INTO video_comments (video_id, user_id, comment) VALUES (?, ?, ?)')
            ->execute([$videoId, $userId, $comment]);
    }

    public static function deleteComment(PDO $db, int $id): void
    {
        $db->prepare('DELETE FROM video_comments WHERE id = ?')->execute([$id]);
    }
}
