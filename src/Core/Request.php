<?php
namespace App\Core;

class Request
{
    public function __construct(
        private array $server,
        private array $get,
        private array $post,
        private array $cookies
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_COOKIE);
    }

    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        return $method === 'HEAD' ? 'GET' : $method;
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /** Query string parameter; non-string values (arrays) are ignored */
    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->get[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    /** POST body parameter; non-string values (arrays) are ignored */
    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function cookie(string $key): ?string
    {
        $value = $this->cookies[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /** Request URI as sent by the client (path and query string) */
    public function uri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    public function path(): string
    {
        return parse_url($this->uri(), PHP_URL_PATH) ?: '/';
    }

    public function scriptName(): string
    {
        return $this->server['SCRIPT_NAME'] ?? '/index.php';
    }

    public function isHttps(): bool
    {
        return (($this->server['HTTPS'] ?? '') === 'on')
            || (($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($this->server['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');
    }

    /** "scheme://host[:port]" */
    public function origin(): string
    {
        $origin = ($this->isHttps() ? 'https' : 'http') . '://' . ($this->server['SERVER_NAME'] ?? 'localhost');
        $port = $this->server['SERVER_PORT'] ?? '80';
        if ($port != '80') {
            $origin .= ':' . $port;
        }
        return $origin;
    }

    public function userAgent(): string
    {
        return mb_substr($this->server['HTTP_USER_AGENT'] ?? '', 0, 255);
    }

    /**
     * Client IP address. X-Forwarded-For is only honoured when the direct peer
     * is a trusted proxy; the chain is walked from the right, skipping proxies.
     */
    public function clientIp(array $trustedProxies = []): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!in_array($ip, $trustedProxies, true) || empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            return $ip;
        }
        $chain = array_reverse(array_map('trim', explode(',', $this->server['HTTP_X_FORWARDED_FOR'])));
        foreach ($chain as $hop) {
            if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                break;
            }
            $ip = $hop;
            if (!in_array($hop, $trustedProxies, true)) {
                break;
            }
        }
        return $ip;
    }
}
