<?php
namespace Tests\Unit;

use App\Auth\Passwords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PasswordsTest extends TestCase
{
    public function testAcceptsAValidPassword(): void
    {
        $this->assertNull(Passwords::validate('Correct-Horse-42', 'Correct-Horse-42', ['alice', 'alice@example.com']));
    }

    public static function invalidPasswords(): array
    {
        return [
            'too short' => ['short', 'short', [], 'au moins 10 caractères'],
            'confirmation differs' => ['Correct-Horse-42', 'Correct-Horse-43', [], 'ne correspondent pas'],
            'equals the username' => ['Long-Username', 'Long-Username', ['long-username'], 'identique'],
            'equals the email' => ['alice@example.com', 'alice@example.com', ['x', 'ALICE@example.com'], 'identique'],
            'too long' => [str_repeat('a', 1025), str_repeat('a', 1025), [], 'trop long'],
        ];
    }

    #[DataProvider('invalidPasswords')]
    public function testRejectsInvalidPasswords(string $password, string $confirmation, array $forbidden, string $expected): void
    {
        $this->assertStringContainsString($expected, (string) Passwords::validate($password, $confirmation, $forbidden));
    }

    public function testCountsCharactersNotBytes(): void
    {
        // 10 accented characters are 20 bytes: still exactly the minimum length
        $this->assertNull(Passwords::validate(str_repeat('é', 10), str_repeat('é', 10)));
    }

    public function testHashesWithArgon2idAndVerifies(): void
    {
        $hash = Passwords::hash('Correct-Horse-42');
        $this->assertStringStartsWith(defined('PASSWORD_ARGON2ID') ? '$argon2id$' : '$2y$', $hash);
        $this->assertTrue(password_verify('Correct-Horse-42', $hash));
        $this->assertFalse(Passwords::needsRehash($hash));
    }

    public function testOldBcryptHashesNeedRehash(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('Argon2id not available: bcrypt is the current algorithm.');
        }
        $this->assertTrue(Passwords::needsRehash(password_hash('x', PASSWORD_BCRYPT)));
    }
}
