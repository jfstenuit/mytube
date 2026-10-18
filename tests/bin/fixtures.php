<?php
// Fixture helper for the browser tests (tests/browser), which can't use PHP directly.
// Prints JSON.
//
//   php tests/bin/fixtures.php setup                    create users, collections, group, videos
//   php tests/bin/fixtures.php cleanup                  remove every "zz" record and the test clip
//   php tests/bin/fixtures.php view-count <video id>
//   php tests/bin/fixtures.php config                   what the browser tests need to know
//   php tests/bin/fixtures.php recaptcha <token> <action>   verify a real reCAPTCHA token

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('MYTUBE_KEEP_CLIP', true);
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Security\Recaptcha;
use Tests\Support\Fixtures;

$fx = new Fixtures();
$command = $argv[1] ?? '';

$out = match ($command) {
    'setup' => (function () use ($fx) {
        if (Config::get('env') !== 'development') {
            throw new RuntimeException('Browser fixtures need APP_ENV=development.');
        }
        $a = $fx->collection('Browser A');
        $b = $fx->collection('Browser B');
        $admin = $fx->user('b_admin', ['admin' => true, 'name' => 'Admin Navigateur']);
        $member = $fx->user('b_member', ['name' => 'Membre Navigateur']);
        $fx->group('browser', [$a, $b], [$member['id']]);
        return [
            'users' => ['admin' => $admin, 'member' => $member],
            'collections' => ['a' => $a, 'b' => $b],
            'videos' => [
                'public' => $fx->video(['collection' => $a, 'title' => 'ZZ Browser public', 'minutesAgo' => 10]),
                'unlisted' => $fx->video(['collection' => $a, 'visibility' => 'unlisted', 'title' => 'ZZ Browser unlisted', 'minutesAgo' => 5]),
                'other' => $fx->video(['collection' => $b, 'title' => 'ZZ Browser B', 'minutesAgo' => 1]),
            ],
        ];
    })(),
    'cleanup' => (function () use ($fx) {
        $fx->cleanup();
        Fixtures::removeClip();
        return ['cleaned' => true];
    })(),
    'view-count' => ['count' => (int) $fx->scalar('SELECT view_count FROM videos WHERE id = ?', [$argv[2] ?? ''])],
    'config' => [
        'site_url' => rtrim(getenv('MYTUBE_TEST_URL') ?: Config::get('site.url'), '/') . '/',
        'env' => Config::get('env'),
        'recaptcha_enabled' => Config::get('recaptcha.site_key') !== '',
        'e2e_override_present' => is_file(MYTUBE_ROOT . '/.env.e2e'),
    ],
    'recaptcha' => ['status' => Recaptcha::fromConfig(parse_url(Config::get('site.url'), PHP_URL_HOST) ?? '')
        ->verify($argv[2] ?? '', $argv[3] ?? '', '127.0.0.1', 'MyTube browser tests')],
    default => throw new InvalidArgumentException("Unknown command: $command"),
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
