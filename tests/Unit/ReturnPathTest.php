<?php
namespace Tests\Unit;

use App\Core\ReturnPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReturnPathTest extends TestCase
{
    private const BASE = '/videos/';

    public static function accepted(): array
    {
        return [
            'home' => ['/videos/'],
            'watch page' => ['/videos/watch?v=Lyvujp52b0y0'],
            'account' => ['/videos/account'],
            'admin with query' => ['/videos/admin/videos/edit?id=abc'],
        ];
    }

    #[DataProvider('accepted')]
    public function testKeepsPathsUnderTheBase(string $target): void
    {
        $this->assertSame($target, ReturnPath::sanitize(self::BASE, $target));
    }

    public static function rejected(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'absolute URL' => ['https://evil.example/'],
            'protocol-relative' => ['//evil.example/'],
            'other path on the host' => ['/other/path'],
            'parent segments' => ['/videos/../../evil'],
            'encoded parent segments' => ['/videos/%2e%2e/evil'],
            'current segment' => ['/videos/./watch'],
            'backslash' => ['/videos/\\evil.example'],
            'javascript scheme' => ['javascript:alert(1)'],
            'control character' => ["/videos/\nSet-Cookie: x=1"],
            'prefix lookalike' => ['/videosx/watch'],
        ];
    }

    #[DataProvider('rejected')]
    public function testFallsBackToTheHomePage(?string $target): void
    {
        $this->assertSame(self::BASE, ReturnPath::sanitize(self::BASE, $target));
    }
}
