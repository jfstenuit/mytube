<?php
namespace App\Auth;

use PDO;

/**
 * Rate limiting of sensitive actions, per subject (e.g. login identifier or
 * email address) and per client IP, based on the throttle_events table.
 */
class Throttle
{
    /**
     * window: seconds; subject/ip: maximum counted events in the window;
     * failures_only: count only failed events (successful logins don't count)
     */
    private const RULES = [
        'login'    => ['window' => 900,  'subject' => 5, 'ip' => 20, 'failures_only' => true],
        'register' => ['window' => 3600, 'subject' => 3, 'ip' => 10, 'failures_only' => false],
        'reset'    => ['window' => 3600, 'subject' => 3, 'ip' => 10, 'failures_only' => false],
        'resend'   => ['window' => 3600, 'subject' => 3, 'ip' => 10, 'failures_only' => false],
        // Logged-in users: current password checks, email change requests (subject "user:ID")
        'password' => ['window' => 900,  'subject' => 5, 'ip' => 20, 'failures_only' => true],
        'email_change' => ['window' => 3600, 'subject' => 3, 'ip' => 10, 'failures_only' => false],
    ];

    public function __construct(private PDO $db)
    {
    }

    public static function normalize(string $subject): string
    {
        return mb_strtolower(trim($subject));
    }

    public function tooMany(string $action, string $subject, string $ip): bool
    {
        $rule = self::RULES[$action];
        $failures = $rule['failures_only'] ? ' AND success = 0' : '';
        $since = 'created_at > NOW() - INTERVAL ' . (int) $rule['window'] . ' SECOND';

        $sth = $this->db->prepare("SELECT
            (SELECT COUNT(*) FROM throttle_events WHERE action = ? AND subject = ? AND $since$failures),
            (SELECT COUNT(*) FROM throttle_events WHERE action = ? AND ip = ? AND $since$failures)");
        $sth->execute([$action, self::normalize($subject), $action, inet_pton($ip)]);
        [$bySubject, $byIp] = $sth->fetch(PDO::FETCH_NUM);

        return $bySubject >= $rule['subject'] || $byIp >= $rule['ip'];
    }

    public function record(string $action, string $subject, string $ip, bool $success): void
    {
        $subject = self::normalize($subject);
        if ($success && self::RULES[$action]['failures_only']) {
            // A successful login clears the failure count for this subject
            $this->db->prepare('DELETE FROM throttle_events WHERE action = ? AND subject = ? AND success = 0')
                ->execute([$action, $subject]);
        }
        $this->db->prepare('INSERT INTO throttle_events (action, subject, ip, success) VALUES (?, ?, ?, ?)')
            ->execute([$action, mb_substr($subject, 0, 255), inet_pton($ip), (int) $success]);

        // Occasional housekeeping
        if (random_int(1, 100) === 1) {
            $this->db->exec('DELETE FROM throttle_events WHERE created_at < NOW() - INTERVAL 1 DAY');
        }
    }
}
