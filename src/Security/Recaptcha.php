<?php
namespace App\Security;

use App\Core\Config;
use RuntimeException;

/**
 * Server-side verification of invisible (score-based) reCAPTCHA tokens.
 *
 * Two backends, selected by RECAPTCHA_MODE (see docs/recaptcha.md):
 * - assessment: Google Cloud CreateAssessment API (project id + API key), recommended
 * - siteverify: legacy siteverify endpoint (legacy secret key)
 */
class Recaptcha
{
    public const OK = 'ok';
    public const DISABLED = 'disabled';
    public const MISSING = 'missing';      // no token: script blocked or not loaded
    public const INVALID = 'invalid';      // bad/expired token, wrong action or hostname
    public const LOW_SCORE = 'low_score';  // probably automated
    public const ERROR = 'error';          // Google unreachable, quota exceeded, misconfiguration

    private const TIMEOUT = 5;

    public function __construct(
        private string $siteKey,
        private string $mode,
        private string $projectId,
        private string $apiKey,
        private string $secretKey,
        private float $minScore,
        private string $expectedHostname
    ) {
    }

    public static function fromConfig(string $expectedHostname): self
    {
        return new self(
            Config::get('recaptcha.site_key'),
            Config::get('recaptcha.mode'),
            Config::get('recaptcha.project_id'),
            Config::get('recaptcha.api_key'),
            Config::get('recaptcha.secret_key'),
            Config::get('recaptcha.min_score'),
            $expectedHostname
        );
    }

    public function enabled(): bool
    {
        return $this->siteKey !== '';
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    /** CreateAssessment keys use enterprise.js / grecaptcha.enterprise on the client */
    public function usesEnterpriseScript(): bool
    {
        return $this->mode !== 'siteverify';
    }

    /** @return string one of the status constants */
    public function verify(?string $token, string $action, string $ip, string $userAgent): string
    {
        if (!$this->enabled()) {
            return self::DISABLED;
        }
        if ($token === null || $token === '') {
            return self::MISSING;
        }

        try {
            $result = $this->mode === 'siteverify'
                ? $this->siteverify($token, $ip)
                : $this->assessment($token, $action, $ip, $userAgent);
        } catch (RuntimeException $e) {
            error_log('reCAPTCHA verification failed: ' . $e->getMessage());
            return self::ERROR;
        }

        if (!$result['valid']) {
            error_log("reCAPTCHA token rejected ($action): " . $result['reason']);
            return self::INVALID;
        }
        if (strcasecmp($result['action'], $action) !== 0) {
            error_log("reCAPTCHA action mismatch: expected $action, got {$result['action']}");
            return self::INVALID;
        }
        if ($this->expectedHostname !== '' && $result['hostname'] !== ''
            && strcasecmp($result['hostname'], $this->expectedHostname) !== 0) {
            error_log("reCAPTCHA hostname mismatch: expected {$this->expectedHostname}, got {$result['hostname']}");
            return self::INVALID;
        }
        if ($result['score'] < $this->minScore) {
            error_log(sprintf('reCAPTCHA low score (%s): %.1f < %.1f', $action, $result['score'], $this->minScore));
            return self::LOW_SCORE;
        }
        return self::OK;
    }

    private function assessment(string $token, string $action, string $ip, string $userAgent): array
    {
        if ($this->projectId === '' || $this->apiKey === '') {
            throw new RuntimeException('RECAPTCHA_PROJECT_ID and RECAPTCHA_API_KEY are required in assessment mode');
        }
        $url = 'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode($this->projectId)
            . '/assessments?key=' . rawurlencode($this->apiKey);
        $response = $this->post($url, 'application/json', json_encode(['event' => [
            'token' => $token,
            'siteKey' => $this->siteKey,
            'expectedAction' => $action,
            'userIpAddress' => $ip,
            'userAgent' => $userAgent,
        ]]));

        return [
            'valid' => (bool) ($response['tokenProperties']['valid'] ?? false),
            'reason' => (string) ($response['tokenProperties']['invalidReason'] ?? 'unknown'),
            'action' => (string) ($response['tokenProperties']['action'] ?? ''),
            'hostname' => (string) ($response['tokenProperties']['hostname'] ?? ''),
            'score' => (float) ($response['riskAnalysis']['score'] ?? 0.0),
        ];
    }

    private function siteverify(string $token, string $ip): array
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('RECAPTCHA_SECRET_KEY is required in siteverify mode');
        }
        $response = $this->post('https://www.google.com/recaptcha/api/siteverify',
            'application/x-www-form-urlencoded',
            http_build_query(['secret' => $this->secretKey, 'response' => $token, 'remoteip' => $ip]));

        return [
            'valid' => (bool) ($response['success'] ?? false),
            'reason' => implode(', ', $response['error-codes'] ?? ['unknown']),
            'action' => (string) ($response['action'] ?? ''),
            'hostname' => (string) ($response['hostname'] ?? ''),
            'score' => (float) ($response['score'] ?? 0.0),
        ];
    }

    /** HTTP POST returning the decoded JSON response (protected for tests) */
    protected function post(string $url, string $contentType, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ["Content-Type: $contentType"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($raw === false) {
            throw new RuntimeException("request failed: $error");
        }
        if ($status !== 200) {
            throw new RuntimeException("HTTP $status: " . mb_substr($raw, 0, 300));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('invalid JSON response');
        }
        return $data;
    }
}
