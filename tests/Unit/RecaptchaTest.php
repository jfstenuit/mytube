<?php
namespace Tests\Unit;

use App\Security\Recaptcha;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Verdicts computed from simulated Google responses (no network) */
class RecaptchaTest extends TestCase
{
    private static function fake(string $mode, mixed $response, string $siteKey = 'SITEKEY', string $projectId = 'my-project',
                                 string $apiKey = 'APIKEY', string $secret = 'SECRET'): Recaptcha
    {
        return new class($siteKey, $mode, $projectId, $apiKey, $secret, 0.5, 'labs.example.org', $response) extends Recaptcha {
            public ?string $lastUrl = null;
            public ?string $lastBody = null;

            public function __construct(string $siteKey, string $mode, string $projectId, string $apiKey, string $secret,
                                        float $minScore, string $hostname, private mixed $response)
            {
                parent::__construct($siteKey, $mode, $projectId, $apiKey, $secret, $minScore, $hostname);
            }

            protected function post(string $url, string $contentType, string $body): array
            {
                $this->lastUrl = $url;
                $this->lastBody = $body;
                if ($this->response instanceof \Throwable) {
                    throw $this->response;
                }
                return $this->response;
            }
        };
    }

    private static function assessment(bool $valid, string $action, string $host, float $score): array
    {
        return ['tokenProperties' => ['valid' => $valid, 'action' => $action, 'hostname' => $host,
            'invalidReason' => $valid ? 'INVALID_REASON_UNSPECIFIED' : 'EXPIRED'], 'riskAnalysis' => ['score' => $score]];
    }

    private static function siteverify(bool $success, string $action, string $host, float $score): array
    {
        return ['success' => $success, 'action' => $action, 'hostname' => $host, 'score' => $score,
            'error-codes' => $success ? [] : ['timeout-or-duplicate']];
    }

    public static function verdicts(): array
    {
        return [
            'assessment: good score' => ['assessment', self::assessment(true, 'register', 'labs.example.org', 0.9), 'tok', Recaptcha::OK],
            'assessment: action case-insensitive' => ['assessment', self::assessment(true, 'REGISTER', 'labs.example.org', 0.9), 'tok', Recaptcha::OK],
            'assessment: low score' => ['assessment', self::assessment(true, 'register', 'labs.example.org', 0.3), 'tok', Recaptcha::LOW_SCORE],
            'assessment: other action' => ['assessment', self::assessment(true, 'login', 'labs.example.org', 0.9), 'tok', Recaptcha::INVALID],
            'assessment: other hostname' => ['assessment', self::assessment(true, 'register', 'evil.example', 0.9), 'tok', Recaptcha::INVALID],
            'assessment: invalid token' => ['assessment', self::assessment(false, 'register', 'labs.example.org', 0.0), 'tok', Recaptcha::INVALID],
            'assessment: HTTP error' => ['assessment', new RuntimeException('HTTP 429'), 'tok', Recaptcha::ERROR],
            'missing token' => ['assessment', self::assessment(true, 'register', 'labs.example.org', 0.9), '', Recaptcha::MISSING],
            'siteverify: good score' => ['siteverify', self::siteverify(true, 'register', 'labs.example.org', 0.7), 'tok', Recaptcha::OK],
            'siteverify: low score' => ['siteverify', self::siteverify(true, 'register', 'labs.example.org', 0.1), 'tok', Recaptcha::LOW_SCORE],
            'siteverify: failure' => ['siteverify', self::siteverify(false, '', '', 0.0), 'tok', Recaptcha::INVALID],
        ];
    }

    #[DataProvider('verdicts')]
    public function testVerdict(string $mode, mixed $response, string $token, string $expected): void
    {
        if (!in_array($expected, [Recaptcha::OK, Recaptcha::MISSING, Recaptcha::DISABLED], true)) {
            $this->expectErrorLog();  // rejections and failures are logged
        }
        $this->assertSame($expected, self::fake($mode, $response)->verify($token, 'register', '192.0.2.1', 'UA'));
    }

    public function testDisabledWithoutSiteKey(): void
    {
        $recaptcha = self::fake('assessment', [], siteKey: '');
        $this->assertFalse($recaptcha->enabled());
        $this->assertSame(Recaptcha::DISABLED, $recaptcha->verify('', 'register', '192.0.2.1', 'UA'));
    }

    public function testMissingCredentialsAreAnError(): void
    {
        $this->expectErrorLog();
        $this->assertSame(Recaptcha::ERROR, (new Recaptcha('SITEKEY', 'assessment', '', '', '', 0.5, ''))->verify('tok', 'register', '192.0.2.1', 'UA'));
        $this->assertSame(Recaptcha::ERROR, (new Recaptcha('SITEKEY', 'siteverify', '', '', '', 0.5, ''))->verify('tok', 'register', '192.0.2.1', 'UA'));
    }

    public function testAssessmentRequestFormat(): void
    {
        $recaptcha = self::fake('assessment', self::assessment(true, 'register', 'labs.example.org', 0.9));
        $recaptcha->verify('tok', 'register', '192.0.2.1', 'UA');
        $this->assertSame('https://recaptchaenterprise.googleapis.com/v1/projects/my-project/assessments?key=APIKEY', $recaptcha->lastUrl);
        $this->assertSame(['event' => ['token' => 'tok', 'siteKey' => 'SITEKEY', 'expectedAction' => 'register',
            'userIpAddress' => '192.0.2.1', 'userAgent' => 'UA']], json_decode($recaptcha->lastBody, true));
    }

    public function testSiteverifyRequestFormat(): void
    {
        $recaptcha = self::fake('siteverify', self::siteverify(true, 'register', 'labs.example.org', 0.9));
        $recaptcha->verify('tok', 'register', '192.0.2.1', 'UA');
        $this->assertSame('https://www.google.com/recaptcha/api/siteverify', $recaptcha->lastUrl);
        parse_str($recaptcha->lastBody, $fields);
        $this->assertSame(['secret' => 'SECRET', 'response' => 'tok', 'remoteip' => '192.0.2.1'], $fields);
    }

    public function testScriptChoiceFollowsTheMode(): void
    {
        $this->assertTrue(self::fake('assessment', [])->usesEnterpriseScript());
        $this->assertFalse(self::fake('siteverify', [])->usesEnterpriseScript());
    }
}
