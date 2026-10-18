--
-- Migration 005: microsecond precision for the times that invalidate sessions
--
-- A session (or remember-me token) is valid only if it was opened after the last password
-- change. With one-second precision, a session opened during the same second as a password
-- reset survived it. Login times are now recorded with microseconds too (microtime()).
--
-- Apply with:
--   mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" < scripts/migrations/005-precise-auth-times.sql
--

ALTER TABLE `users`
  MODIFY `password_changed_at` datetime(6) DEFAULT NULL;

ALTER TABLE `user_tokens`
  MODIFY `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6);

INSERT INTO `schema_migrations` (`version`) VALUES ('005-precise-auth-times');
