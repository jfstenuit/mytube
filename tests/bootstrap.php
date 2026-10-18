<?php
// PHPUnit bootstrap: autoloading and configuration (.env of the project)

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;

define('MYTUBE_ROOT', dirname(__DIR__));

Config::load(MYTUBE_ROOT);

// The generated test clip (tests/Support/Fixtures.php) never outlives a PHPUnit run;
// tests/bin/fixtures.php keeps it between its "setup" and "cleanup" commands
if (!defined('MYTUBE_KEEP_CLIP')) {
    register_shutdown_function([\Tests\Support\Fixtures::class, 'removeClip']);
}
