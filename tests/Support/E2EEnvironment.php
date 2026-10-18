<?php
namespace Tests\Support;

use App\Core\Config;

/**
 * Shared state of an end-to-end run, set up once per PHPUnit process:
 * - checks that the instance is a development one and answers at its base URL
 * - starts the mail sink and writes .env.e2e so that the site sends mail to it and
 *   disables reCAPTCHA (scripts can't obtain real tokens)
 * - on exit: removes .env.e2e, stops the sink, removes fixtures and the test clip
 */
final class E2EEnvironment
{
    private static ?self $instance = null;
    private static ?string $unavailable = null;

    public readonly string $baseUrl;
    public readonly MailSink $mail;

    public static function get(): ?self
    {
        if (self::$instance === null && self::$unavailable === null) {
            try {
                self::$instance = new self();
            } catch (\RuntimeException $e) {
                self::$unavailable = $e->getMessage();
            }
        }
        return self::$instance;
    }

    public static function unavailableReason(): ?string
    {
        return self::$unavailable;
    }

    private function __construct()
    {
        if (Config::get('env') !== 'development') {
            throw new \RuntimeException('End-to-end tests only run against an instance with APP_ENV=development.');
        }
        $this->baseUrl = rtrim(getenv('MYTUBE_TEST_URL') ?: Config::get('site.url'), '/') . '/';
        $probe = (new HttpClient($this->baseUrl))->get('login');
        if ($probe->status !== 200) {
            throw new \RuntimeException("The site doesn't answer at {$this->baseUrl} (HTTP {$probe->status}).");
        }

        $this->mail = new MailSink((int) (getenv('MYTUBE_TEST_SMTP_PORT') ?: 2526), MYTUBE_ROOT . '/tests/output/mail');
        $this->mail->start();
        file_put_contents(self::overrideFile(), implode("\n", [
            '# Written by the end-to-end tests (tests/Support/E2EEnvironment.php) and removed at the end.',
            '# Only honoured with APP_ENV=development. Delete it if a test run was interrupted.',
            'SMTP_HOST=127.0.0.1',
            'SMTP_PORT=' . $this->mail->port,
            'SMTP_USER=',
            'SMTP_PASS=',
            'SMTP_SECURE=',
            'RECAPTCHA_SITE_KEY=',
            '',
        ]));
        chmod(self::overrideFile(), 0644);
        register_shutdown_function([$this, 'shutdown']);
    }

    public function shutdown(): void
    {
        @unlink(self::overrideFile());
        $this->mail->stop();
        (new Fixtures())->cleanup();
        Fixtures::removeClip();
        foreach (glob(MYTUBE_ROOT . '/media/zz_*') ?: [] as $path) {
            is_dir($path) ? exec('rm -rf ' . escapeshellarg($path)) : @unlink($path);
        }
    }

    private static function overrideFile(): string
    {
        return MYTUBE_ROOT . '/.env.e2e';
    }
}
