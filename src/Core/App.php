<?php
namespace App\Core;

use App\Auth\Auth;
use App\Auth\Throttle;
use App\Auth\TokenRepository;
use App\Security\Recaptcha;
use PDO;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** Request-scoped application services and response helpers */
class App
{
    public readonly Request $request;
    public readonly Session $session;
    public readonly Csrf $csrf;
    public readonly Auth $auth;
    public readonly TokenRepository $tokens;
    public readonly Throttle $throttle;
    public readonly Recaptcha $recaptcha;
    public readonly Environment $twig;
    /** Application base path, e.g. "/videos/" */
    public readonly string $basePath;
    /** Absolute application base URL, e.g. "https://example.com/videos/" */
    public readonly string $baseUrl;
    /** Path relative to the base ("" for the home page, "watch", "login"…) */
    public readonly string $route;
    public readonly string $clientIp;

    private ?Mailer $mailer = null;

    public function __construct(public readonly string $rootDir)
    {
        $this->request = Request::fromGlobals();

        // Base path taken from the location of index.php, so that the root URL
        // and trailing slashes resolve correctly
        $this->basePath = rtrim(str_replace('\\', '/', dirname($this->request->scriptName())), '/') . '/';
        $this->baseUrl = $this->request->origin() . $this->basePath;
        $path = $this->request->path();
        $route = str_starts_with($path, $this->basePath) ? substr($path, strlen($this->basePath)) : '';
        $this->route = $route === 'index.php' ? '' : $route;
        $this->clientIp = $this->request->clientIp(Config::get('trusted_proxies'));

        $secure = $this->request->isHttps();
        $this->session = new Session($this->basePath, $secure, $this->request->cookie(Session::COOKIE_NAME) !== null);
        $this->csrf = new Csrf($this->session);
        $this->tokens = new TokenRepository($this->db());
        $this->throttle = new Throttle($this->db());
        $this->auth = new Auth($this->db(), $this->session, $this->csrf, $this->tokens, $this->throttle,
            $this->request, $this->clientIp, $this->basePath, $secure);
        $this->recaptcha = Recaptcha::fromConfig(
            parse_url(Config::get('site.url'), PHP_URL_HOST) ?? ($_SERVER['SERVER_NAME'] ?? ''));

        $this->twig = self::createTwig($rootDir);
        $this->twig->addFunction(new TwigFunction('csrf_token', fn () => $this->csrf->token()));
        $this->twig->addGlobal('recaptcha_site_key', $this->recaptcha->siteKey());
        $this->twig->addGlobal('recaptcha_enterprise', $this->recaptcha->usesEnterpriseScript());
    }

    /** Twig environment shared by web pages, mail and CLI scripts */
    public static function createTwig(string $rootDir): Environment
    {
        $twig = new Environment(new FilesystemLoader($rootDir . '/templates'), [
            'cache' => $rootDir . '/twig/cache',
            'auto_reload' => true,
            // Escaping by file extension: HTML for *.twig, none for *.txt.twig (mail)
            'autoescape' => 'name',
        ]);
        // asset('assets/js/x.js') -> 'assets/js/x.js?v=<mtime>', so browsers fetch
        // the new version as soon as a file changes instead of using a stale cache
        $twig->addFunction(new TwigFunction('asset', function (string $path) use ($rootDir): string {
            $mtime = @filemtime($rootDir . '/' . $path);
            return $mtime ? $path . '?v=' . $mtime : $path;
        }));
        $twig->addGlobal('footer_copyright', Config::get('footer.copyright'));
        $twig->addGlobal('site_name', Config::get('site.name'));
        return $twig;
    }

    public function db(): PDO
    {
        return Database::get();
    }

    public function mailer(): Mailer
    {
        return $this->mailer ??= new Mailer($this->twig);
    }

    /**
     * Absolute URL of a path under the application, for links leaving the site
     * (emails). Built from SITE_URL, never from the request's Host header.
     */
    public function publicUrl(string $path): string
    {
        $base = Config::get('site.url');
        if ($base === '') {
            error_log('SITE_URL is not set; falling back to the request host for ' . $path);
            $base = $this->baseUrl;
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Checks the reCAPTCHA token posted with the form for $action.
     * Strict mode rejects anything but a good score; lenient mode (login) only
     * rejects tokens Google flags (invalid or low score), and lets requests through
     * when the check couldn't run (script blocked, Google unreachable) since other
     * protections (throttling) still apply.
     *
     * @return string|null error message to display, or null if the request may proceed
     */
    public function recaptchaError(string $action, bool $strict = true): ?string
    {
        $status = $this->recaptcha->verify($this->request->input('recaptcha_token'), $action,
            $this->clientIp, $this->request->userAgent());
        return match (true) {
            $status === Recaptcha::OK, $status === Recaptcha::DISABLED => null,
            $status === Recaptcha::INVALID, $status === Recaptcha::LOW_SCORE =>
                'Votre demande n\'a pas passé la vérification anti-robot. Veuillez réessayer.',
            !$strict => null,
            $status === Recaptcha::MISSING =>
                'La vérification anti-robot n\'a pas pu se charger. Vérifiez qu\'aucun bloqueur n\'empêche l\'accès à google.com, puis réessayez.',
            default => 'La vérification anti-robot est momentanément indisponible. Veuillez réessayer dans quelques minutes.',
        };
    }

    public function render(string $template, array $context = [], int $status = 200): void
    {
        $context += [
            'current_user' => $this->auth->user()?->toTemplate(),
            'current_url' => $this->request->uri(),
            'flashes' => $this->session->takeFlashes(),
        ];
        // Render first: the template may start the session (CSRF token), which sends headers
        $html = $this->twig->render($template, $context);
        http_response_code($status);
        echo $html;
    }

    /** Redirect to a path under the application ("" = home) or an absolute path */
    public function redirect(string $location, int $status = 302): void
    {
        if (!str_starts_with($location, '/')) {
            $location = $this->basePath . $location;
        }
        header('Location: ' . $this->request->origin() . $location, true, $status);
    }

    public function notFound(): void
    {
        $this->render('404.twig', [], 404);
    }

    public function error(int $status, string $message): void
    {
        $this->render('error.twig', ['status' => $status, 'message' => $message], $status);
    }

    /** Checks the CSRF token of a POST; renders an error page and returns false if invalid */
    public function checkCsrf(): bool
    {
        if ($this->csrf->isValid($this->request->input('csrf_token'))) {
            return true;
        }
        $this->error(400, 'Le formulaire a expiré. Veuillez recharger la page et réessayer.');
        return false;
    }

    /**
     * Validates a post-login "return" target: only absolute paths under the
     * application base are accepted (no scheme, host, protocol-relative URL or
     * "."/".." segments escaping the base). Anything else falls back to the home page.
     */
    public function safeReturnPath(?string $target): string
    {
        $path = $target !== null ? rawurldecode((string) parse_url($target, PHP_URL_PATH)) : '';
        if ($target === null
            || !str_starts_with($target, $this->basePath)
            || str_starts_with($target, '//')
            || preg_match('/[\\\\\x00-\x1F\x7F]/', $target)
            || preg_match('#(^|/)\.\.?(/|$)#', $path)
            || parse_url($target, PHP_URL_HOST) !== null
            || parse_url($target, PHP_URL_SCHEME) !== null) {
            return $this->basePath;
        }
        return $target;
    }
}
