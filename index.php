<?php
function generateVideoThumbnail($videoFile, $thumbnailFile, $timePosition = '00:02:20') {
    // Escape shell arguments to prevent command injection
    $videoFileEscaped = escapeshellarg($videoFile);
    $thumbnailFileEscaped = escapeshellarg($thumbnailFile);
    $timePositionEscaped = escapeshellarg($timePosition);

    // Construct the ffmpeg command
    $command = "ffmpeg -v error -ss $timePositionEscaped -i $videoFileEscaped -frames:v 1 -update 1 $thumbnailFileEscaped 2>&1";

    // Execute the command
    $output = [];
    $returnVar = 0;
    exec($command, $output, $returnVar);

    // Check for success or failure
    if ($returnVar === 0) {
        return true; // Thumbnail generated successfully
    } else {
        // Log or display error details for debugging
        error_log("FFmpeg failed: " . implode("\n", $output));
        return false; // Thumbnail generation failed
    }
}

function getVideoResolution($videoFile) {
    // Construct the ffmpeg command
    $cmd = sprintf(
        'ffprobe -v error -select_streams v:0 -show_entries stream=width,height ' .
        '-of default=noprint_wrappers=1:nokey=1 %s 2>&1',
        escapeshellarg($videoFile)
    );

    // Execute the command
    $output = [];
    $returnVar = 0;
    exec($cmd, $output, $returnVar);

    if ($returnVar === 0 && count($output) >= 2) {
        $width = (int)trim($output[0]);
        $height = (int)trim($output[1]);
        if ($width > 0 && $height > 0) {
            $resolution = "{$width}x{$height}";
            return $resolution;
        }
    } else {
        error_log("Could not extract resolution from video: $videoFile - ".implode("\\n",$output));
    }
    return false;
}

require __DIR__ . '/vendor/autoload.php';
use Twig\Loader\FilesystemLoader;
use Twig\Environment;
use App\Core\Config;
use App\Core\Database;

Config::load(__DIR__);

// Twig setup
$loader = new FilesystemLoader(__DIR__ . '/templates');
$twig = new Environment($loader, [
    'cache' => __DIR__ . '/twig/cache',
    'auto_reload' => true
]);

$urlprefix = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ||
         (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
          && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') ||
         (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])
          && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on'))
		? 'https':'http';

$urlprefix .= "://";
if ($_SERVER["SERVER_PORT"] != "80") {
  $urlprefix .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"];
 } else {
  $urlprefix .= $_SERVER["SERVER_NAME"];
}
// Base path of the application (e.g. "/videos/"), taken from the location of
// index.php so that the root URL and trailing slashes resolve correctly
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$action = str_starts_with($requestPath, $basePath) ? substr($requestPath, strlen($basePath)) : '';
if ($action === 'index.php') $action = '';
$urlprefix .= $basePath;

// --- User session (DRAFT: authentication is not implemented yet) ---
$currentUser = null;

// DRAFT: in development, ?preview_user=1 / ?preview_user=0 toggles a fake
// logged-in user so the user menu and account page can be reviewed.
// Remove once real authentication lands.
if (Config::get('env') === 'development') {
    if (isset($_GET['preview_user'])) {
        $preview = $_GET['preview_user'] === '1';
        setcookie('preview_user', $preview ? '1' : '', ['expires' => $preview ? 0 : 1, 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['preview_user'] = $preview ? '1' : '';
    }
    if (!empty($_COOKIE['preview_user'])) {
        $currentUser = [
            'username' => 'demo',
            'display_name' => 'Démo Utilisateur',
            'email' => 'demo@example.com',
            'is_admin' => true,
            'groups' => ['admin', 'Famille'],
        ];
    }
}

// asset('assets/js/x.js') -> 'assets/js/x.js?v=<mtime>', so browsers fetch
// the new version as soon as a file changes instead of using a stale cache
$twig->addFunction(new \Twig\TwigFunction('asset', function (string $path): string {
    $mtime = @filemtime(__DIR__ . '/' . $path);
    return $mtime ? $path . '?v=' . $mtime : $path;
}));

$twig->addGlobal('current_user', $currentUser);
$twig->addGlobal('csrf_token', '');
$twig->addGlobal('current_url', $_SERVER['REQUEST_URI']);
$twig->addGlobal('footer_copyright', Config::get('footer.copyright'));

$draftNotice = 'Brouillon : l\'authentification n\'est pas encore implémentée.';

if ($action == 'login') {
    echo $twig->render('login.twig', [
        'return_to' => $_GET['return'] ?? '',
        'identifier' => $_POST['identifier'] ?? '',
        'notice' => isset($_GET['logged_out']) ? 'Vous êtes déconnecté.' : null,
        'error' => $_SERVER['REQUEST_METHOD'] === 'POST' ? $draftNotice : null,
    ]);
    exit(0);
}

if ($action == 'account') {
    if (!$currentUser) {
        header('Location: login?return=' . urlencode($_SERVER['REQUEST_URI']));
        exit(0);
    }
    echo $twig->render('account.twig', [
        'error' => $_SERVER['REQUEST_METHOD'] === 'POST' ? $draftNotice : null,
    ]);
    exit(0);
}

if ($action == 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }
    setcookie('preview_user', '', ['expires' => 1, 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: login?logged_out=1');
    exit(0);
}

// The home page is the watch page of a randomly selected video
$isHome = ($action === '');
if ($isHome) $action = 'watch';

if (($action!='watch') && ($action!='embed') && ($action!='oembed')) {
	header("HTTP/1.0 404 Not Found");
    echo $twig->render('404.twig');
	exit(0);
}

$dbh = Database::get();

if ($isHome) {
    // Home page: show a random public video
    $v = $dbh->query("SELECT id FROM videos WHERE visibility = 'public' ORDER BY RAND() LIMIT 1")->fetchColumn();
    if ($v === false) {
        http_response_code(404);
        echo $twig->render('404.twig');
        exit(0);
    }
    // A different video on each visit: never cache this response
    header('Cache-Control: no-store');
} else {
    $v = $_GET["v"] ?? '';
}

if (!preg_match('/^[A-Za-z0-9_-]{12}$/', $v)) {
    http_response_code(400);
    exit('Invalid video ID');
}

$query = "SELECT * FROM videos WHERE videos.id=?";
$sth = $dbh->prepare($query);
$sth->execute(array($v));
$video = $sth->fetch(PDO::FETCH_ASSOC);

if (!isset($video['path'])) {
    header("HTTP/1.0 404 Not Found");
    error_log("Missing video path for ID: " . htmlspecialchars($v));
    echo $twig->render('404.twig');
    exit(0);
}

// we have a path from the DB - now check if it's a mp4 file, a m3u8 or if it's missing
$mediaRoot = realpath(__DIR__ . '/media');
$mediaUrl  = 'media/'; 

$videoId = $video['path'];

$candidates = [
    "$videoId.mp4",
    "$videoId/manifest/video.m3u8",
    "$videoId/master.m3u8"
];

$videoPath = null;
$videoUrlPath = null;

foreach ($candidates as $relPath) {
    $fullPath = $mediaRoot . '/' . $relPath;
    $resolved = realpath($fullPath);

    if ($resolved && str_starts_with($resolved, $mediaRoot . '/')) {
        $videoPath = $resolved;
        $videoUrlPath = $mediaUrl . $relPath;
        break;
    }
}

if (!$videoPath) {
    error_log("Invalid video path for ID: " . htmlspecialchars($v));
    http_response_code(404);
    echo $twig->render('404.twig');
    exit;
}

// Check if we have a thumbnail
$thumbsPath = __DIR__ . '/thumbs/' . $v . '.jpg';
if (!file_exists($thumbsPath)) {
    generateVideoThumbnail($videoPath, $thumbsPath);
}

// Check for video resolution
if (empty($video['resolution'])) {
    $resolution=getVideoResolution($videoPath);
    if ($resolution) {
        // Store the resolution in DB
        $update = $dbh->prepare("UPDATE videos SET resolution = ? WHERE id = ?");
        $update->execute([$resolution, $v]);
        // Inject values into $video
        $video['resolution'] = $resolution;        
    }
}

if (preg_match('/^(\d+)x(\d+)$/', $video['resolution'], $m)) {
    $width = (int)$m[1];
    $height = (int)$m[2];
    if ($width > 0) {
        $video['aspect_ratio'] = round($height / $width, 6);
    }
}    


$seealso=array();
$query = "SELECT * FROM videos WHERE collection_id=? AND id!=? ORDER BY title";
$sth = $dbh->prepare($query);
$sth->execute(array($video['collection_id'],$v));
while ( $row = $sth->fetch(PDO::FETCH_ASSOC) ) {
    array_push($seealso, $row);
}

$og=array(
    'title'=>$video['title'],
    'description'=>$video['description'],
    'type'=>'video.movie',
    'locale'=>'fr_BE',
    'url'=>sprintf('%swatch?v=%s',$urlprefix,$v),
    'image'=>$urlprefix."thumbs/".$v.".jpg",
    'image:width'=>1920,
    'image:height'=>1080
);

$video["encoded_video"] = base64_encode($urlprefix . $videoUrlPath);
$video["encoded_thumbnail"] = base64_encode($urlprefix . "thumbs/".$v.".jpg");

$context = [
    'og' => $og,
    'video' => $video,
    'seealso' => $seealso,
    'urlprefix' => $urlprefix,
    'bmc_enabled' => Config::get('bmc.enabled'),
    'bmc_slug' => Config::get('bmc.slug'),
    'bmc_button_text' => Config::get('bmc.button_text')
];

if ($action=='watch') {
    echo $twig->render('watch.twig', $context);
} elseif ($action=='embed') {
    echo $twig->render('embed.twig', $context);
} elseif ($action=='oembed') {
    if (isset($_GET['format']) && $_GET['format'] === 'xml') {
        header('Content-Type: text/xml; charset=utf-8');
        echo $twig->render('oembed-xml.twig', $context);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo $twig->render('oembed-json.twig', $context);
    }
}

?>
