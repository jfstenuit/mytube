<?php
namespace App\Core;

use Dotenv\Dotenv;

class Config
{
    private static array $settings = [];

    public static function load(string $basePath): void
    {
        if (empty(self::$settings)) {
            $dotenv = Dotenv::createImmutable($basePath);
            $dotenv->load();

            self::$settings = [
                'db.host'          => $_ENV['DB_HOST']          ?? 'localhost',
                'db.name'          => $_ENV['DB_NAME']          ?? '',
                'db.user'          => $_ENV['DB_USER']          ?? '',
                'db.pass'          => $_ENV['DB_PASS']          ?? '',
                'site.url'         => $_ENV['SITE_URL']         ?? '',
                'env'              => $_ENV['APP_ENV']          ?? 'production',
                'footer.copyright' => $_ENV['FOOTER_COPYRIGHT'] ?? '',
                'bmc.enabled'      => filter_var($_ENV['BMC_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
                'bmc.slug'         => $_ENV['BMC_SLUG']         ?? '',
                'bmc.button_text'  => $_ENV['BMC_BUTTON_TEXT']  ?? 'Buy me a coffee'
            ];
        }
    }

    public static function get(string $key, $default = null): mixed
    {
        return self::$settings[$key] ?? $default;
    }
}
