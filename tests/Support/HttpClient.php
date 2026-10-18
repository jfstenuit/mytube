<?php
namespace Tests\Support;

use CurlHandle;

/**
 * Minimal browser for end-to-end tests: one cookie jar per instance (curl's cookie
 * engine), no automatic redirects, a desktop browser user agent.
 */
class HttpClient
{
    public const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0 Safari/537.36 MyTubeTests';

    private CurlHandle $ch;

    public function __construct(private string $baseUrl, private string $userAgent = self::USER_AGENT)
    {
        $this->ch = curl_init();
        curl_setopt($this->ch, CURLOPT_COOKIEFILE, '');  // enables the in-memory cookie engine
    }

    public function url(string $path): string
    {
        return preg_match('#^https?://#', $path) ? $path : $this->baseUrl . ltrim($path, '/');
    }

    public function get(string $path, array $headers = []): Response
    {
        return $this->request('GET', $path, null, $headers);
    }

    /** @param array<string, mixed> $fields arrays are sent as name[]=… */
    public function post(string $path, array $fields = [], array $headers = []): Response
    {
        return $this->request('POST', $path, self::encode($fields), $headers);
    }

    /** POST a form with the CSRF token taken from $formPage */
    public function submit(string $formPage, string $action, array $fields = [], array $headers = []): Response
    {
        $token = $this->get($formPage)->csrfToken();
        return $this->post($action, ['csrf_token' => $token] + $fields, $headers);
    }

    public function login(array $user, bool $remember = false, ?string $return = null): Response
    {
        $fields = ['identifier' => $user['username'], 'password' => $user['password']];
        if ($remember) {
            $fields['remember'] = '1';
        }
        if ($return !== null) {
            $fields['return'] = $return;
        }
        return $this->submit('login', 'login', $fields);
    }

    public function logout(): Response
    {
        return $this->submit('account', 'logout');
    }

    /** Current value of a cookie in the jar */
    public function cookie(string $name): ?string
    {
        foreach (curl_getinfo($this->ch, CURLINFO_COOKIELIST) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (($fields[5] ?? null) === $name) {
                return $fields[6] ?? '';
            }
        }
        return null;
    }

    /** Adds (or replaces) a host-only cookie, as the site itself would set it */
    public function setCookie(string $name, string $value): void
    {
        $this->removeCookie($name);
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        $path = parse_url($this->baseUrl, PHP_URL_PATH) ?: '/';
        // Netscape cookie-file line: domain, include subdomains, path, secure, expiry, name, value
        curl_setopt($this->ch, CURLOPT_COOKIELIST, implode("\t", ["#HttpOnly_$host", 'FALSE', $path, 'TRUE', '0', $name, $value]));
    }

    /** Drops a cookie (e.g. the session cookie, to simulate a browser restart) */
    public function removeCookie(string $name): void
    {
        $keep = array_filter(curl_getinfo($this->ch, CURLINFO_COOKIELIST) ?: [],
            fn ($line) => (explode("\t", $line)[5] ?? null) !== $name);
        curl_setopt($this->ch, CURLOPT_COOKIELIST, 'ALL');
        foreach ($keep as $line) {
            curl_setopt($this->ch, CURLOPT_COOKIELIST, $line);
        }
    }

    public function clearCookies(): void
    {
        curl_setopt($this->ch, CURLOPT_COOKIELIST, 'ALL');
    }

    private function request(string $method, string $path, ?string $body, array $headers): Response
    {
        $responseHeaders = [];
        curl_setopt_array($this->ch, [
            CURLOPT_URL => $this->url($path),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POST => $method === 'POST',
            CURLOPT_POSTFIELDS => $body ?? '',
            CURLOPT_HTTPGET => $method === 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))][] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $content = curl_exec($this->ch);
        if ($content === false) {
            throw new \RuntimeException('HTTP request failed: ' . curl_error($this->ch));
        }
        return new Response(curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE), $responseHeaders, $content);
    }

    private static function encode(array $fields): string
    {
        $parts = [];
        foreach ($fields as $name => $value) {
            foreach ((array) $value as $item) {
                $parts[] = rawurlencode(is_array($value) ? "{$name}[]" : $name) . '=' . rawurlencode((string) $item);
            }
        }
        return implode('&', $parts);
    }
}
