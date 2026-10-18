<?php
namespace Tests\Integration;

use App\Auth\Throttle;
use Tests\Support\IntegrationTestCase;

/** Uses documentation addresses (192.0.2.0/24) and "zz" subjects, removed after each test */
class ThrottleTest extends IntegrationTestCase
{
    private Throttle $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->throttle = new Throttle($this->fx->db());
    }

    public function testLoginAllowsFiveFailuresPerIdentifier(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->throttle->record('login', 'zz_victim', '192.0.2.10', false);
        }
        $this->assertFalse($this->throttle->tooMany('login', 'zz_victim', '192.0.2.11'));
        $this->throttle->record('login', 'zz_victim', '192.0.2.10', false);
        $this->assertTrue($this->throttle->tooMany('login', 'ZZ_Victim ', '192.0.2.11'), 'subjects are normalized');
        $this->assertFalse($this->throttle->tooMany('login', 'zz_other', '192.0.2.11'));
    }

    public function testSuccessfulLoginClearsTheFailures(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->throttle->record('login', 'zz_forgetful', '192.0.2.20', false);
        }
        $this->throttle->record('login', 'zz_forgetful', '192.0.2.20', true);
        $this->throttle->record('login', 'zz_forgetful', '192.0.2.20', false);
        $this->assertFalse($this->throttle->tooMany('login', 'zz_forgetful', '192.0.2.21'));
    }

    public function testLoginAllowsTwentyFailuresPerIp(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->throttle->record('login', "zz_user$i", '192.0.2.30', false);
        }
        $this->assertTrue($this->throttle->tooMany('login', 'zz_fresh', '192.0.2.30'));
        $this->assertFalse($this->throttle->tooMany('login', 'zz_fresh', '192.0.2.31'));
    }

    public function testRegistrationCountsEveryAttempt(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->throttle->record('register', 'zz_new@example.com', '192.0.2.40', true);
        }
        $this->assertTrue($this->throttle->tooMany('register', 'zz_new@example.com', '192.0.2.41'));
    }

    public function testViewsAreLimitedPerIpOnly(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->throttle->record('view', 'zzVideo00001', '192.0.2.50', true);
        }
        $this->assertTrue($this->throttle->tooMany('view', 'zzVideo00002', '192.0.2.50'));
        $this->assertFalse($this->throttle->tooMany('view', 'zzVideo00001', '192.0.2.51'), 'no per-video limit');
    }

    public function testIpv6AddressesAreSupported(): void
    {
        $this->throttle->record('comment', 'user:zz', '2001:db8::1', true);
        $this->assertFalse($this->throttle->tooMany('comment', 'user:zz', '2001:db8::1'));
    }
}
