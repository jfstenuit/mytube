--
-- Migration 002: authentication tokens, email verification, generic throttling,
-- likes/comments attached to user accounts
--
-- Apply with:
--   mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" < scripts/migrations/002-tokens.sql
--
-- Note: DDL statements auto-commit in MySQL/MariaDB, so this migration is not
-- transactional. Back up the database before applying it.
--

--
-- Users: email verification; password becomes optional (future passkey-only accounts)
--
ALTER TABLE `users`
  ADD COLUMN `email_verified_at` datetime DEFAULT NULL AFTER `email`,
  MODIFY `password_hash` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL;

--
-- Single-purpose secrets (remember-me cookies, email verification, password reset,
-- email change). The cookie or link carries "selector:validator"; only a SHA-256
-- hash of the validator is stored.
--
CREATE TABLE `user_tokens` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('remember','verify_email','reset_password','change_email') NOT NULL,
  `selector` char(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `validator_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `data` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_tokens_selector` (`selector`),
  KEY `idx_user_tokens_user` (`user_id`, `type`),
  KEY `idx_user_tokens_expires` (`expires_at`),
  CONSTRAINT `fk_user_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Generic throttling (login, registration, password reset)
--
RENAME TABLE `login_attempts` TO `throttle_events`;
ALTER TABLE `throttle_events`
  ADD COLUMN `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'login' AFTER `id`,
  CHANGE `username` `subject` varchar(255) NOT NULL,
  CHANGE `attempted_at` `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  DROP INDEX `idx_login_attempts_username`,
  DROP INDEX `idx_login_attempts_ip`,
  ADD KEY `idx_throttle_events_subject` (`action`, `subject`, `created_at`),
  ADD KEY `idx_throttle_events_ip` (`action`, `ip`, `created_at`),
  ADD KEY `idx_throttle_events_created` (`created_at`);
ALTER TABLE `throttle_events`
  ALTER COLUMN `action` DROP DEFAULT;

--
-- Likes and comments: anonymous id for logged-out visitors, user id when logged in
-- (both tables are still empty at this point)
--
ALTER TABLE `video_likes`
  DROP PRIMARY KEY,
  CHANGE `user_id` `anon_id` char(32) DEFAULT NULL,
  ADD COLUMN `id` bigint(20) NOT NULL AUTO_INCREMENT FIRST,
  ADD COLUMN `user_id` int(11) DEFAULT NULL AFTER `anon_id`,
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_video_likes_anon` (`video_id`, `anon_id`),
  ADD UNIQUE KEY `uq_video_likes_user` (`video_id`, `user_id`),
  ADD KEY `idx_video_likes_user` (`user_id`),
  ADD CONSTRAINT `fk_video_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chk_video_likes_owner` CHECK (`anon_id` IS NOT NULL OR `user_id` IS NOT NULL);

ALTER TABLE `video_comments`
  CHANGE `user_id` `anon_id` char(32) DEFAULT NULL,
  ADD COLUMN `user_id` int(11) DEFAULT NULL AFTER `anon_id`,
  ADD KEY `idx_video_comments_video` (`video_id`, `posted_at`),
  ADD KEY `idx_video_comments_user` (`user_id`),
  ADD CONSTRAINT `fk_video_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chk_video_comments_owner` CHECK (`anon_id` IS NOT NULL OR `user_id` IS NOT NULL);

INSERT INTO `schema_migrations` (`version`) VALUES ('002-tokens');
