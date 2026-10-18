<?php
namespace App\Stats;

use App\Auth\Throttle;
use App\Models\User;
use PDO;

/**
 * View counting (docs/auth-plan.md, phase 8).
 *
 * The watch and embed pages carry a signed token; the player reports a view once the
 * video has actually played for threshold() seconds. A report counts if the token is
 * valid, enough real time has passed since the page was served, the viewer hasn't been
 * counted for this video within the last hour, and the IP is under its hourly cap.
 */
class ViewCounter
{
    private const THRESHOLD_SECONDS = 30;
    private const TOKEN_MAX_AGE = 6 * 3600;
    /** Margin on the elapsed-time check (clock rounding, slow page loads) */
    private const ELAPSED_TOLERANCE = 2;
    private const DEDUP_WINDOW = 3600;

    public const COUNTED = 'counted';
    public const DUPLICATE = 'duplicate';
    public const REJECTED = 'rejected';

    /** User agents that never count (crawlers, link previews, command-line tools) */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|curl|wget|python|java\/|go-http|headless/i';

    public function __construct(private PDO $db, private Throttle $throttle, private string $secret)
    {
    }

    public function enabled(): bool
    {
        return strlen($this->secret) >= 32;
    }

    /** Playback needed for a view: 30 s, or half of a shorter video */
    public static function threshold(?float $duration): float
    {
        if ($duration === null || $duration <= 0) {
            return self::THRESHOLD_SECONDS;
        }
        return round(min(self::THRESHOLD_SECONDS, $duration / 2), 1);
    }

    /** Token embedded in the page: "issued.signature", bound to the video */
    public function token(string $videoId, ?int $now = null): string
    {
        $issued = $now ?? time();
        return $issued . '.' . $this->sign("view|$videoId|$issued");
    }

    /**
     * @return string one of COUNTED, DUPLICATE, REJECTED
     */
    public function record(array $video, string $token, ?User $user, string $ip, string $userAgent): string
    {
        if (!$this->enabled() || preg_match(self::BOT_PATTERN, $userAgent) || $userAgent === '') {
            return self::REJECTED;
        }

        // Signed by us for this video, not too old, and the threshold has really elapsed
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])
            || !hash_equals($this->sign("view|{$video['id']}|{$parts[0]}"), $parts[1])) {
            return self::REJECTED;
        }
        $elapsed = time() - (int) $parts[0];
        $threshold = self::threshold($video['duration'] !== null ? (float) $video['duration'] : null);
        if ($elapsed > self::TOKEN_MAX_AGE || $elapsed + self::ELAPSED_TOLERANCE < $threshold) {
            return self::REJECTED;
        }

        if ($this->throttle->tooMany('view', $video['id'], $ip)) {
            return self::REJECTED;
        }

        $viewer = $this->viewerHash($user, $ip, $userAgent);
        $sth = $this->db->prepare('SELECT 1 FROM video_views
            WHERE video_id = ? AND viewer_hash = ? AND viewed_at > NOW() - INTERVAL ' . self::DEDUP_WINDOW . ' SECOND LIMIT 1');
        $sth->execute([$video['id'], $viewer]);
        if ($sth->fetchColumn()) {
            return self::DUPLICATE;
        }

        $this->db->prepare('INSERT INTO video_views (video_id, user_id, viewer_hash) VALUES (?, ?, ?)')
            ->execute([$video['id'], $user?->id, $viewer]);
        $this->db->prepare('UPDATE videos SET view_count = view_count + 1 WHERE id = ?')->execute([$video['id']]);
        $this->throttle->record('view', $video['id'], $ip, true);
        return self::COUNTED;
    }

    /**
     * Logged-in viewers: their account. Anonymous viewers: (day, IP, user agent), so no
     * cookie is needed and no IP address is stored; the day makes it unlinkable over time.
     */
    private function viewerHash(?User $user, string $ip, string $userAgent): string
    {
        return $user !== null
            ? $this->sign('user|' . $user->id)
            : $this->sign('anon|' . date('Y-m-d') . '|' . $ip . '|' . $userAgent);
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret);
    }
}
