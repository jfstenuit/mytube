--
-- Full schema for a fresh installation.
-- Existing installations should apply the files in scripts/migrations/ instead.
--

SET FOREIGN_KEY_CHECKS = 0;
-- Allow inserting the default collection with id 0
SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO');

--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
CREATE TABLE `schema_migrations` (
  `version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `collections`
--

DROP TABLE IF EXISTS `collections`;
CREATE TABLE `collections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `sighting_blocklists`
--

DROP TABLE IF EXISTS `sighting_blocklists`;
CREATE TABLE `sighting_blocklists` (
  `id` int(11) NOT NULL,
  `org_uuid` varchar(40) NOT NULL,
  `created` datetime NOT NULL,
  `org_name` varchar(255) NOT NULL,
  `comment` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT 'NULL'
);


--
-- Table structure for table `uploads`
--

DROP TABLE IF EXISTS `uploads`;
CREATE TABLE `uploads` (
  `id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `size` bigint(20) NOT NULL,
  `offset` bigint(20) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
);

--
-- Table structure for table `video_comments`
--

DROP TABLE IF EXISTS `video_comments`;
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


--
-- Table structure for table `video_likes`
--

DROP TABLE IF EXISTS `video_likes`;
CREATE TABLE `video_likes` (
  `video_id` char(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(11) NOT NULL,
  `liked_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`video_id`, `user_id`),
  KEY `idx_video_likes_user` (`user_id`),
  CONSTRAINT `fk_video_likes_video` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_video_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `video_views`
--   viewer_hash: HMAC of the user id, or of (day, IP, user agent) for anonymous viewers
--

DROP TABLE IF EXISTS `video_views`;
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

--
-- Table structure for table `videos`
--   visibility: public (listed), unlisted (link only), private (group access)
--

DROP TABLE IF EXISTS `videos`;
CREATE TABLE `videos` (
  `id` char(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `collection_id` int(11) NOT NULL,
  `visibility` enum('public','unlisted','private') NOT NULL DEFAULT 'private',
  `title` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `path` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `cdnurl` text CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `resolution` varchar(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `duration` decimal(10,3) DEFAULT NULL,
  `view_count` int(10) unsigned NOT NULL DEFAULT 0,
  `description` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `FK_COLLECTIONS` (`collection_id`),
  KEY `idx_videos_collection_created` (`collection_id`, `created_at`),
  CONSTRAINT `fk_videos_collection` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `display_name` varchar(255) NOT NULL DEFAULT '',
  `password_hash` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login_at` datetime DEFAULT NULL,
  `password_changed_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `user_groups`
--

DROP TABLE IF EXISTS `user_groups`;
CREATE TABLE `user_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `is_builtin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_groups_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `group_members`
--

DROP TABLE IF EXISTS `group_members`;
CREATE TABLE `group_members` (
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `added_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`group_id`, `user_id`),
  KEY `idx_group_members_user` (`user_id`),
  CONSTRAINT `fk_group_members_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_group_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `group_collections`
--

DROP TABLE IF EXISTS `group_collections`;
CREATE TABLE `group_collections` (
  `group_id` int(11) NOT NULL,
  `collection_id` int(11) NOT NULL,
  `granted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`group_id`, `collection_id`),
  KEY `idx_group_collections_collection` (`collection_id`),
  CONSTRAINT `fk_group_collections_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_group_collections_collection` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `throttle_events`
--   action: login, register, reset
--

DROP TABLE IF EXISTS `throttle_events`;
CREATE TABLE `throttle_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject` varchar(255) NOT NULL,
  `ip` varbinary(16) NOT NULL,
  `success` tinyint(1) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_throttle_events_subject` (`action`, `subject`, `created_at`),
  KEY `idx_throttle_events_ip` (`action`, `ip`, `created_at`),
  KEY `idx_throttle_events_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `user_tokens`
--   remember-me cookies, email verification, password reset, email change;
--   only a SHA-256 hash of the validator is stored
--

DROP TABLE IF EXISTS `user_tokens`;
CREATE TABLE `user_tokens` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('remember','verify_email','reset_password','change_email') NOT NULL,
  `selector` char(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `validator_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `data` varchar(255) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
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

SET FOREIGN_KEY_CHECKS = 1;

--
-- Seed data
--

INSERT INTO `collections` (`id`, `name`) VALUES (0, 'The default collection');

INSERT INTO `user_groups` (`id`, `name`, `description`, `is_builtin`)
VALUES (1, 'admin', 'Administrateurs : accès complet à toutes les collections et à l''administration', 1);

INSERT INTO `schema_migrations` (`version`) VALUES ('001-auth'), ('002-tokens'), ('003-video-created-at'), ('004-engagement'), ('005-precise-auth-times');
