<?php
namespace Tests\Unit;

use App\Core\App;
use App\Media\VideoProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Twig helpers registered by App::createTwig(), and small pure helpers */
class TwigExtensionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mytube-twig-test-' . bin2hex(random_bytes(4));
        mkdir("$this->root/templates", 0777, true);
        mkdir("$this->root/assets", 0777, true);
        file_put_contents("$this->root/assets/app.js", '//');
        touch("$this->root/assets/app.js", 1700000000);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function render(string $template, array $context = []): string
    {
        return App::createTwig($this->root)->createTemplate($template)->render($context);
    }

    public static function sizes(): array
    {
        return [
            [null, '—'],
            [0, '0 o'],
            [999, '999 o'],
            [1536, '1,5 Ko'],
            [110911560, '105,8 Mo'],
            [5 * 1024 ** 3, '5,0 Go'],
        ];
    }

    #[DataProvider('sizes')]
    public function testFilesizeFilter(?int $bytes, string $expected): void
    {
        $this->assertSame($expected, $this->render('{{ size|filesize }}', ['size' => $bytes]));
    }

    public function testAssetAddsTheModificationTime(): void
    {
        $this->assertSame('assets/app.js?v=1700000000', $this->render("{{ asset('assets/app.js') }}"));
        $this->assertSame('assets/missing.js', $this->render("{{ asset('assets/missing.js') }}"));
    }

    public function testMailTemplatesAreNotHtmlEscaped(): void
    {
        file_put_contents("$this->root/templates/sample.txt.twig", '{{ name }}');
        file_put_contents("$this->root/templates/sample.twig", '{{ name }}');
        $twig = App::createTwig($this->root);
        $this->assertSame("O'Brien <b>", $twig->render('sample.txt.twig', ['name' => "O'Brien <b>"]));
        $this->assertSame('O&#039;Brien &lt;b&gt;', $twig->render('sample.twig', ['name' => "O'Brien <b>"]));
    }

    public static function thumbnailTimes(): array
    {
        return [[null, 5.0], [0.0, 5.0], [8.0, 1.0], [81.1, 8.1], [292.6, 29.3], [3600.0, 140.0]];
    }

    #[DataProvider('thumbnailTimes')]
    public function testDefaultThumbnailTime(?float $duration, float $expected): void
    {
        $this->assertSame($expected, VideoProbe::defaultThumbnailTime($duration));
    }
}
