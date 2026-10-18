<?php
namespace App\Controllers;

use App\Auth\Access;
use App\Core\App;
use App\Core\Config;
use App\Media\MediaLibrary;
use App\Media\VideoProbe;
use App\Models\Engagement;
use App\Stats\ViewCounter;
use PDO;

class VideoController
{
    private Access $access;

    public function __construct(private App $app)
    {
        $this->access = new Access($app->db(), $app->auth->user());
    }

    /** Home page: watch page of a random video among those listed for this viewer */
    public function home(): void
    {
        [$listable, $params] = $this->access->listableCondition('v');
        $sth = $this->app->db()->prepare("SELECT v.id FROM videos v WHERE $listable ORDER BY RAND() LIMIT 1");
        $sth->execute($params);
        $v = $sth->fetchColumn();
        if ($v === false) {
            $this->welcome();
            return;
        }
        // A different video on each visit: never cache this response
        header('Cache-Control: no-store');
        $this->show('watch', $v);
    }

    /**
     * Home page when no video is listed for this viewer (e.g. only unlisted and private
     * videos): the watch layout with an illustration instead of the player.
     */
    private function welcome(): void
    {
        $user = $this->app->auth->user();
        header('Vary: Cookie');
        $this->app->render('welcome.twig', [
            'state' => $user === null ? 'anonymous' : ($user->isAdmin() ? 'admin' : 'member'),
            'bmc_enabled' => Config::get('bmc.enabled'),
            'bmc_slug' => Config::get('bmc.slug'),
            'bmc_button_text' => Config::get('bmc.button_text'),
        ]);
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

        if (!$this->access->canView($video)) {
            $this->denied($mode);
            return;
        }

        // we have a path from the DB - now check if it's a mp4 file, a m3u8 or if it's missing
        $media = (new MediaLibrary($app->rootDir . '/media'))->locate($video['path']);
        if ($media === null) {
            error_log("Invalid video path for ID: " . htmlspecialchars($v));
            $app->notFound();
            return;
        }
        $videoPath = $media['file'];
        $videoUrlPath = $media['url'];

        // Videos added through the admin UI already have a thumbnail and a resolution;
        // older entries get them on first view
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

        // Duration (view threshold for short videos); older entries get it on first view
        if ($video['duration'] === null && $mode !== 'oembed') {
            $info = VideoProbe::probe($videoPath);
            if ($info && $info['duration'] !== null) {
                $dbh->prepare('UPDATE videos SET duration = ? WHERE id = ?')->execute([round($info['duration'], 3), $v]);
                $video['duration'] = $info['duration'];
            }
        }

        if (preg_match('/^(\d+)x(\d+)$/', $video['resolution'], $m)) {
            $width = (int) $m[1];
            $height = (int) $m[2];
            if ($width > 0) {
                $video['aspect_ratio'] = round($height / $width, 6);
            }
        }

        // Other videos of the collection that this viewer may see listed. On an unlisted video's
        // page, its unlisted siblings are listed too: sharing one link shares the collection.
        [$listable, $params] = $this->access->listableCondition('v',
            $video['visibility'] === 'unlisted' ? (int) $video['collection_id'] : null);
        $sth = $dbh->prepare("SELECT v.* FROM videos v WHERE v.collection_id = ? AND v.id != ? AND $listable ORDER BY v.title");
        $sth->execute(array_merge([$video['collection_id'], $v], $params));
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

        // View counting: signed token and playback threshold for the player (watch and embed)
        $counter = new ViewCounter($dbh, $app->throttle, Config::get('app.secret'));
        if ($counter->enabled()) {
            $context['view_token'] = $counter->token($v);
            $context['view_threshold'] = ViewCounter::threshold($video['duration'] !== null ? (float) $video['duration'] : null);
        }

        if ($mode === 'watch') {
            $context['collection_nav'] = $this->collectionNav((int) $video['collection_id']);
            $context += $this->engagement($video);
            // The sidebar depends on who is logged in
            header('Vary: Cookie');
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

    /**
     * Video the viewer may not watch. Anonymous visitors of the watch page are sent
     * to the login page (a shared private link works once logged in); otherwise it
     * looks exactly like a missing video.
     */
    private function denied(string $mode): void
    {
        $app = $this->app;
        if ($mode === 'watch' && $app->auth->user() === null) {
            $app->redirect('login?return=' . urlencode($app->request->uri()));
        } elseif ($mode === 'oembed') {
            // oEmbed specification: 401 for a private resource
            http_response_code(401);
            echo 'Unauthorized';
        } else {
            $app->notFound();
        }
    }

    /**
     * Current collection, and the other collections with at least one video listed
     * for this viewer, each linking to its most recently added such video.
     */
    private function collectionNav(int $collectionId): array
    {
        $db = $this->app->db();
        $sth = $db->prepare('SELECT name FROM collections WHERE id = ?');
        $sth->execute([$collectionId]);
        $current = ['id' => $collectionId, 'name' => (string) $sth->fetchColumn()];

        [$listable, $params] = $this->access->listableCondition('v');
        $sth = $db->prepare("SELECT c.id, c.name,
                (SELECT v.id FROM videos v WHERE v.collection_id = c.id AND $listable
                 ORDER BY v.created_at DESC, v.title LIMIT 1) AS latest_video
            FROM collections c WHERE c.id != ?
            HAVING latest_video IS NOT NULL ORDER BY c.name");
        $sth->execute(array_merge($params, [$collectionId]));

        return ['current' => $current, 'others' => $sth->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** Likes and comments of the watch page, and what the viewer may do with them */
    private function engagement(array $video): array
    {
        $app = $this->app;
        $db = $app->db();
        $user = $app->auth->user();
        $canEngage = $user !== null && $this->access->hasCollectionAccess((int) $video['collection_id']);
        $draft = $canEngage ? $app->session->get('comment_draft') : null;
        return [
            'like_count' => Engagement::likeCount($db, $video['id']),
            'liked' => $canEngage && Engagement::hasLiked($db, $video['id'], $user->id),
            // anonymous: invite to log in; member: may like and comment; other: not a member
            'engage_state' => $user === null ? 'anonymous' : ($canEngage ? 'member' : 'outsider'),
            'comments' => Engagement::comments($db, $video['id']),
            'comment_draft' => ($draft['video'] ?? null) === $video['id'] ? $draft['text'] : '',
            'comment_max' => Engagement::COMMENT_MAX_LENGTH,
            'is_admin' => $user?->isAdmin() ?? false,
        ];
    }
}
