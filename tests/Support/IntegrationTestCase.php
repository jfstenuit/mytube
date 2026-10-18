<?php
namespace Tests\Support;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

/** Tests against the development database; "zz" fixtures are removed after each test */
abstract class IntegrationTestCase extends TestCase
{
    protected Fixtures $fx;

    protected function setUp(): void
    {
        if (Config::get('env') !== 'development') {
            $this->markTestSkipped('Integration tests only run with APP_ENV=development (they write to the database).');
        }
        $this->fx = new Fixtures();
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }
}
