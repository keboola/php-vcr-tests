<?php

declare(strict_types=1);

namespace Keboola\VcrTests\Tests;

use Keboola\VcrTests\VcrControlClient;
use RuntimeException;

class VcrControlClientTest extends FakeProxyTestCase
{
    public function testStartAndStop(): void
    {
        $client = new VcrControlClient($this->startFakeProxy());

        $started = $client->start('/code/tests/datadir/a/source/data/cassettes/requests.json');
        self::assertSame('replay', $started['mode']);
        $stopped = $client->stop();
        self::assertSame([], $stopped['unmatched']);

        $calls = $this->calls();
        self::assertSame('/start', $calls[0]['path']);
        self::assertSame(['cassette' => '/code/tests/datadir/a/source/data/cassettes/requests.json'], $calls[0]['body']);
        self::assertSame('/stop', $calls[1]['path']);
    }

    public function testErrorAnswerIsAnException(): void
    {
        $client = new VcrControlClient($this->startFakeProxy());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown endpoint');
        $method = new \ReflectionMethod($client, 'post');
        $method->setAccessible(true);
        $method->invoke($client, '/nope', []);
    }

    public function testUnreachableProxy(): void
    {
        $client = new VcrControlClient('http://127.0.0.1:1', 2.0);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot reach');
        $client->stop();
    }

    public function testModeFromEnvironment(): void
    {
        putenv('VCR_CONTROL_URL');
        self::assertNull(VcrControlClient::fromEnvironment());
        self::assertSame('live', VcrControlClient::mode());

        putenv('VCR_CONTROL_URL=http://vcr:8081');
        self::assertSame('replay', VcrControlClient::mode());
        self::assertTrue(VcrControlClient::isReplay());
        putenv('VCR_MODE=record');
        self::assertSame('record', VcrControlClient::mode());
        self::assertFalse(VcrControlClient::isReplay());
    }
}
