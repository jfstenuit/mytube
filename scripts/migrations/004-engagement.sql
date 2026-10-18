--
-- Migration 004: view counts, likes and comments tied to user accounts
--
-- Apply with:
--   mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" < scripts/migrations/004-engagement.sql
-- then fill in the duration of existing videos:
--   php scripts/migrations/004-backfill-duration.php
--
-- video_likes and video_comments are recreated: they were designed for anonymous ids and
-- were still empty. Likes and comments now require a logged-in user with collection access.
--

ALTER TABLE `videos`
  ADD COLUMN `duration` decimal(10,3) DEFAULT NULL AFTER `resolution`,
  ADD COLUMN `view_count` int(10) unsigned NOT NULL DEFAULT 0 AFTER `duration`;

--
-- Counted views (raw events, so suspicious ones can be removed and counts recomputed)
--   viewer_hash: HMAC of the user id, or of (day, IP, user agent) for anonymous viewers
--
CREATE TABLE `video_views` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `video_id` char(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `viewer_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `viewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_video_views_viewer` (`video_id`, `viewer_hash`, `viewed_at`),
  KEY `idx_video_views_date` (`viewed_at`),
  KEY `idx_video_views_user` (`user_id`),
  CONSTRAINT `fk_video_views_video` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_video_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE `video_likes`;
CREATE TABLE `video_likes` (
  `video_id` char(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(11) NOT NULL,
  `liked_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`video_id`, `user_id`),
  KEY `idx_video_likes_user` (`user_id`),
  CONSTRAINT `fk_video_likes_video` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_video_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE `video_comments`;
CREATE TABLE `video_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `video_id` char(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `posted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_video_comments_video` (`video_id`, `posted_at`),
  KEY `idx_video_comments_user` (`user_id`),
  CONSTRAINT `fk_video_comments_video` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_video_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`) VALUES ('004-engagement');
