--
-- Table structure for table `collections`
--

DROP TABLE IF EXISTS `collections`;
CREATE TABLE `collections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  PRIMARY KEY (`id`)
);

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
  `video_id` varchar(64) NOT NULL,
  `user_id` char(32) NOT NULL,
  `comment` text NOT NULL,
  `posted_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
);


--
-- Table structure for table `video_likes`
--

DROP TABLE IF EXISTS `video_likes`;
CREATE TABLE `video_likes` (
  `video_id` varchar(64) NOT NULL,
  `user_id` char(32) NOT NULL,
  `liked_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`video_id`,`user_id`)
);

--
-- Table structure for table `videos`
--

DROP TABLE IF EXISTS `videos`;
CREATE TABLE `videos` (
  `id` char(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `collection_id` int(11) NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `path` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `cdnurl` text CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `description` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_COLLECTIONS` (`collection_id`)
);
