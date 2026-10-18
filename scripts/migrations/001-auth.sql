--
-- Migration 001: user authentication, groups and video visibility
--
-- Apply with:
--   mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" < scripts/migrations/001-auth.sql
--
-- Note: DDL statements auto-commit in MySQL/MariaDB, so this migration is not
-- transactional. Back up the database before applying it.
--

--
-- Migration bookkeeping
--
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Video visibility
--   public   : listed and playable by anyone
--   unlisted : playable by anyone who knows the link, never listed publicly
--   private  : only for members of a group granted the video's collection (and admins)
--
-- Existing videos become 'public' to preserve current behaviour; new videos
-- default to 'private' so nothing is exposed by accident.
--
ALTER TABLE `videos`
  ADD COLUMN `visibility` enum('public','unlisted','private') NOT NULL DEFAULT 'public' AFTER `collection_id`;
ALTER TABLE `videos`
  ALTER COLUMN `visibility` SET DEFAULT 'private';
ALTER TABLE `videos`
  ADD CONSTRAINT `fk_videos_collection` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`);

--
-- Users
--
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `email` varchar(255) NOT NULL,
  `display_name` varchar(255) NOT NULL DEFAULT '',
  `password_hash` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Groups ("groups" is a reserved word in MySQL 8, hence the prefix)
--
CREATE TABLE `user_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `is_builtin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_groups_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Built-in admin group: full access to every collection and to administration.
-- Its id is fixed so the code can refer to it reliably.
INSERT INTO `user_groups` (`id`, `name`, `description`, `is_builtin`)
VALUES (1, 'admin', 'Administrateurs : accès complet à toutes les collections et à l''administration', 1);

--
-- Group membership (many-to-many users <-> groups)
--
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
-- Collection grants (many-to-many groups <-> collections)
--
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
-- Login attempts, for brute-force throttling (per username and per IP)
--
CREATE TABLE `login_attempts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `ip` varbinary(16) NOT NULL,
  `success` tinyint(1) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_username` (`username`, `attempted_at`),
  KEY `idx_login_attempts_ip` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`) VALUES ('001-auth');
