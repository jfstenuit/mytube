<?php
namespace Tests\Support;

/** HTTP response as seen by HttpClient */
class Response
{
    /** @param array<string, string[]> $headers lower-cased names */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function location(): ?string
    {
        return $this->header('location');
    }

    /** Names of the cookies set by this response */
    public function cookiesSet(): array
    {
        return array_map(fn ($h) => strtok($h, '='), $this->headers['set-cookie'] ?? []);
    }

    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    public function contains(string $text): bool
    {
        return str_contains($this->body, $text);
    }

    /** Text content of the elements matching a CSS-like class name, decoded */
    public function texts(string $class): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $this->body);
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]");
        $texts = [];
        foreach ($nodes as $node) {
            $texts[] = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        }
        return $texts;
    }

    /** Flash messages and form errors ("alert" boxes) */
    public function alerts(): array
    {
        return $this->texts('alert');
    }

    /** Titles of the videos listed in the watch page sidebar */
    public function sidebarTitles(): array
    {
        return array_map(fn ($t) => $t, $this->texts('video-card'));
    }

    /** Collections in the "Autres collections" menu: name => video id it links to */
    public function switcherLinks(): array
    {
        preg_match_all('#<li><a href="watch\?v=([^"]+)">([^<]+)</a></li>#', $this->body, $m, PREG_SET_ORDER);
        $links = [];
        foreach ($m as [, $id, $name]) {
            $links[html_entity_decode($name, ENT_QUOTES)] = $id;
        }
        return $links;
    }

    public function csrfToken(): ?string
    {
        return preg_match('/name="csrf_token" value="([^"]+)"/', $this->body, $m) ? $m[1] : null;
    }
}
