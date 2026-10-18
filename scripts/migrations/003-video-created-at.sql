--
-- Migration 003: creation date of videos (collection links lead to the most recent video)
--
-- Apply with:
--   mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" < scripts/migrations/003-video-created-at.sql
-- then, optionally, date existing videos from their media files:
--   php scripts/migrations/003-backfill-created-at.php
--

ALTER TABLE `videos`
  ADD COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() AFTER `description`,
  ADD KEY `idx_videos_collection_created` (`collection_id`, `created_at`);

INSERT INTO `schema_migrations` (`version`) VALUES ('003-video-created-at');
