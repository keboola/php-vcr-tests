<?php

declare(strict_types=1);

namespace Keboola\VcrTests\Tests\Fixtures\Hook;

use Keboola\VcrTests\VcrControlClient;
use Keboola\VcrTests\VcrTestCaseTrait;
use PHPUnit\Framework\TestCase;

/**
 * Run by VcrTestCaseTraitTest in a PHPUnit subprocess. setUp(), the test and tearDown() each call /status, so the
 * fake proxy log shows whether the cassette wraps them.
 */
class HookExample extends TestCase
{
    use VcrTestCaseTrait;

    protected function setUp(): void
    {
        $this->mark('setUp');
    }

    protected function tearDown(): void
    {
        $this->mark('tearDown');
    }

    public function testPlain(): void
    {
        $this->mark('test');
        self::assertTrue(true);
    }

    /**
     * @dataProvider provideCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideCases')]
    public function testWithData(string $value): void
    {
        $this->mark($value);
        self::assertNotSame('', $value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideCases(): array
    {
        return ['first case' => ['a'], 'second/case' => ['b']];
    }

    public function testLiveOnly(): void
    {
        $this->vcrSkipInReplay('needs the live API');
        $this->mark('live-only');
    }

    private function mark(string $where): void
    {
        $client = VcrControlClient::fromEnvironment();
        if ($client !== null) {
            file_put_contents((string) getenv('FAKE_VCR_LOG'), json_encode(['path' => 'marker', 'body' => $where]) . "\n", FILE_APPEND);
            $client->status();
        }
    }
}
