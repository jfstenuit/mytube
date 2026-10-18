<?php
// Send a test message through the configured SMTP smarthost.
//
// Usage: php scripts/send-test-mail.php <recipient>

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Mailer;

if ($argc !== 2 || !filter_var($argv[1], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php {$argv[0]} <recipient>\n");
    exit(1);
}

$root = dirname(__DIR__);
Config::load($root);

try {
    (new Mailer(App::createTwig($root)))->send($argv[1], '', 'test', ['site_url' => Config::get('site.url')]);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "✔ Test message sent to {$argv[1]} via " . Config::get('smtp.host') . ':' . Config::get('smtp.port') . "\n";
