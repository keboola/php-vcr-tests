<?php

declare(strict_types=1);

namespace Keboola\VcrTests\Tests\Legacy;

use Keboola\VcrTests\VcrControlClient;
use Keboola\VcrTests\VcrTestCaseTrait;
use PHPUnit\Framework\TestCase;

/**
 * HookExample for PHPUnit 6 and 7, whose setUp()/tearDown() have no return type. Run by tests/legacy/smoke.sh.
 */
class HookExampleLegacy extends TestCase
{
    use VcrTestCaseTrait;

    protected function setUp()
    {
        $this->mark('setUp');
    }

    protected function tearDown()
    {
        $this->mark('tearDown');
    }

    public function testPlain()
    {
        $this->mark('test');
        self::assertTrue(true);
    }

    public function testLiveOnly()
    {
        $this->vcrSkipInReplay('needs the live API');
        $this->mark('live-only');
    }

    private function mark(string $where)
    {
        $client = VcrControlClient::fromEnvironment();
        if ($client !== null) {
            file_put_contents((string) getenv('FAKE_VCR_LOG'), json_encode(['path' => 'marker', 'body' => $where]) . "\n", FILE_APPEND);
            $client->status();
        }
    }
}
