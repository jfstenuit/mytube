<?php
// One-off companion of migration 004: probes and stores the duration of existing videos.
//
// Usage: php scripts/migrations/004-backfill-duration.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Media\MediaLibrary;
use App\Media\VideoProbe;

Config::load($root);
$db = Database::get();
$library = new MediaLibrary($root . '/media');
$update = $db->prepare('UPDATE videos SET duration = ? WHERE id = ?');

foreach ($db->query('SELECT id, path, title FROM videos WHERE duration IS NULL') as $video) {
    $media = $library->locate($video['path']);
    $info = $media ? VideoProbe::probe($media['file']) : null;
    if ($info === null || $info['duration'] === null) {
        echo "- {$video['id']} ({$video['title']}): duration unknown, unchanged\n";
        continue;
    }
    $update->execute([round($info['duration'], 3), $video['id']]);
    printf("✔ %s (%s): %.1f s\n", $video['id'], $video['title'], $info['duration']);
}
