<?php
// One-off companion of migration 003: sets videos.created_at of existing videos
// to the modification time of their media file (MP4) or directory (HLS).
//
// Usage: php scripts/migrations/003-backfill-created-at.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Media\MediaLibrary;

Config::load($root);
$db = Database::get();
$library = new MediaLibrary($root . '/media');
$update = $db->prepare('UPDATE videos SET created_at = FROM_UNIXTIME(?) WHERE id = ?');

foreach ($db->query('SELECT id, path, title FROM videos') as $video) {
    $media = $library->locate($video['path']);
    if ($media === null) {
        echo "- {$video['id']} ({$video['title']}): media not found, unchanged\n";
        continue;
    }
    // HLS: the directory's date, not the manifest's
    $target = $media['type'] === 'hls' ? $root . '/media/' . $video['path'] : $media['file'];
    $mtime = filemtime($target);
    $update->execute([$mtime, $video['id']]);
    echo "✔ {$video['id']} ({$video['title']}): " . date('Y-m-d H:i', $mtime) . "\n";
}
