<?php
namespace App\Controllers;

use App\Core\App;
use App\Core\Config;
use App\Media\VideoProbe;
use PDO;

class VideoController
{
    public function __construct(private App $app)
    {
    }

    /** Home page: watch page of a random public video */
    public function home(): void
    {
        $v = $this->app->db()
            ->query("SELECT id FROM videos WHERE visibility = 'public' ORDER BY RAND() LIMIT 1")
            ->fetchColumn();
        if ($v === false) {
            $this->app->notFound();
            return;
        }
        // A different video on each visit: never cache this response
        header('Cache-Control: no-store');
        $this->show('watch', $v);
    }

    public function watch(): void
    {
        $this->show('watch', $this->app->request->query('v', ''));
    }

    public function embed(): void
    {
        $this->show('embed', $this->app->request->query('v', ''));
    }

    public function oembed(): void
    {
        $this->show('oembed', $this->app->request->query('v', ''));
    }

    private function show(string $mode, string $v): void
    {
        $app = $this->app;
        if (!preg_match('/^[A-Za-z0-9_-]{12}$/', $v)) {
            http_response_code(400);
            echo 'Invalid video ID';
            return;
        }

        $dbh = $app->db();
        $sth = $dbh->prepare('SELECT * FROM videos WHERE videos.id = ?');
        $sth->execute([$v]);
        $video = $sth->fetch(PDO::FETCH_ASSOC);

        if (!isset($video['path'])) {
            error_log("Missing video path for ID: " . htmlspecialchars($v));
            $app->notFound();
            return;
        }

        // we have a path from the DB - now check if it's a mp4 file, a m3u8 or if it's missing
        $mediaRoot = realpath($app->rootDir . '/media');
        $mediaUrl = 'media/';
        $videoId = $video['path'];
        $candidates = [
            "$videoId.mp4",
            "$videoId/manifest/video.m3u8",
            "$videoId/master.m3u8",
        ];

        $videoPath = null;
        $videoUrlPath = null;
        foreach ($candidates as $relPath) {
            $resolved = realpath($mediaRoot . '/' . $relPath);
            if ($resolved && str_starts_with($resolved, $mediaRoot . '/')) {
                $videoPath = $resolved;
                $videoUrlPath = $mediaUrl . $relPath;
                break;
            }
        }

        if (!$videoPath) {
            error_log("Invalid video path for ID: " . htmlspecialchars($v));
            $app->notFound();
            return;
        }

        // Check if we have a thumbnail
        $thumbsPath = $app->rootDir . '/thumbs/' . $v . '.jpg';
        if (!file_exists($thumbsPath)) {
            VideoProbe::generateThumbnail($videoPath, $thumbsPath);
        }

        // Check for video resolution
        if (empty($video['resolution'])) {
            $resolution = VideoProbe::resolution($videoPath);
            if ($resolution) {
                $update = $dbh->prepare('UPDATE videos SET resolution = ? WHERE id = ?');
                $update->execute([$resolution, $v]);
                $video['resolution'] = $resolution;
            }
        }

        if (preg_match('/^(\d+)x(\d+)$/', $video['resolution'], $m)) {
            $width = (int) $m[1];
            $height = (int) $m[2];
            if ($width > 0) {
                $video['aspect_ratio'] = round($height / $width, 6);
            }
        }

        $sth = $dbh->prepare('SELECT * FROM videos WHERE collection_id = ? AND id != ? ORDER BY title');
        $sth->execute([$video['collection_id'], $v]);
        $seealso = $sth->fetchAll(PDO::FETCH_ASSOC);

        $urlprefix = $app->baseUrl;
        $og = [
            'title' => $video['title'],
            'description' => $video['description'],
            'type' => 'video.movie',
            'locale' => 'fr_BE',
            'url' => sprintf('%swatch?v=%s', $urlprefix, $v),
            'image' => $urlprefix . "thumbs/" . $v . ".jpg",
            'image:width' => 1920,
            'image:height' => 1080,
        ];

        $video["encoded_video"] = base64_encode($urlprefix . $videoUrlPath);
        $video["encoded_thumbnail"] = base64_encode($urlprefix . "thumbs/" . $v . ".jpg");

        $context = [
            'og' => $og,
            'video' => $video,
            'seealso' => $seealso,
            'urlprefix' => $urlprefix,
            'bmc_enabled' => Config::get('bmc.enabled'),
            'bmc_slug' => Config::get('bmc.slug'),
            'bmc_button_text' => Config::get('bmc.button_text'),
        ];

        if ($mode === 'watch') {
            $app->render('watch.twig', $context);
        } elseif ($mode === 'embed') {
            $app->render('embed.twig', $context);
        } elseif ($app->request->query('format') === 'xml') {
            header('Content-Type: text/xml; charset=utf-8');
            $app->render('oembed-xml.twig', $context);
        } else {
            header('Content-Type: application/json; charset=utf-8');
            $app->render('oembed-json.twig', $context);
        }
    }
}
