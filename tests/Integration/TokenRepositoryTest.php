<?php
namespace Tests\Integration;

use App\Auth\TokenRepository;
use Tests\Support\IntegrationTestCase;

class TokenRepositoryTest extends IntegrationTestCase
{
    private TokenRepository $tokens;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new TokenRepository($this->fx->db());
        $this->userId = $this->fx->user('tokens')['id'];
    }

    public function testCreatesSplitTokensAndStoresOnlyAHash(): void
    {
        $token = $this->tokens->create($this->userId, 'remember', 3600, null, 'UA', '192.0.2.1');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}:[A-Za-z0-9_-]{43}$/', $token);
        [$selector, $validator] = explode(':', $token);
        $stored = $this->fx->scalar('SELECT validator_hash FROM user_tokens WHERE selector = ?', [$selector]);
        $this->assertSame(hash('sha256', $validator), $stored);
        $this->assertStringNotContainsString($validator, $stored);
    }

    public function testLookupReportsValidatorAndExpiry(): void
    {
        $token = $this->tokens->create($this->userId, 'verify_email', 3600, 'data');
        $row = $this->tokens->lookup('verify_email', $token);
        $this->assertTrue($row['validator_ok']);
        $this->assertFalse($row['expired']);
        $this->assertSame('data', $row['data']);
        $this->assertSame($this->userId, (int) $row['user_id']);

        [$selector] = explode(':', $token);
        $this->assertFalse($this->tokens->lookup('verify_email', "$selector:wrong-validator")['validator_ok']);
        $this->assertNull($this->tokens->lookup('reset_password', $token), 'other token type');
        $this->assertNull($this->tokens->lookup('verify_email', 'malformed'));
        $this->assertNull($this->tokens->lookup('verify_email', str_repeat('A', 24) . ':x'), 'unknown selector');

        $expired = $this->tokens->create($this->userId, 'verify_email', -10);
        $this->assertTrue($this->tokens->lookup('verify_email', $expired)['expired']);
    }

    public function testRotationReplacesTheValidator(): void
    {
        $old = $this->tokens->create($this->userId, 'remember', 60);
        $new = $this->tokens->rotate($this->tokens->lookup('remember', $old), 3600);
        $this->assertSame(explode(':', $old)[0], explode(':', $new)[0], 'same selector');
        $this->assertTrue($this->tokens->lookup('remember', $new)['validator_ok']);
        $this->assertFalse($this->tokens->lookup('remember', $old)['validator_ok'], 'the old cookie no longer matches');
    }

    public function testDeletionByUserAndType(): void
    {
        $remember = $this->tokens->create($this->userId, 'remember', 3600);
        $reset = $this->tokens->create($this->userId, 'reset_password', 3600);
        $this->tokens->deleteForUser($this->userId, 'remember');
        $this->assertNull($this->tokens->lookup('remember', $remember));
        $this->assertNotNull($this->tokens->lookup('reset_password', $reset));
        $this->tokens->deleteForUser($this->userId);
        $this->assertNull($this->tokens->lookup('reset_password', $reset));
    }
}
