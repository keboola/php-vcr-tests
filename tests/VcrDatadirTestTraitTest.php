<?php

declare(strict_types=1);

namespace Keboola\VcrTests\Tests;

use Keboola\DatadirTests\DatadirTestCase;
use Keboola\DatadirTests\DatadirTestSpecification;
use Keboola\VcrTests\VcrDatadirTestTrait;
use PHPUnit\Framework\AssertionFailedError;

class VcrDatadirTestTraitTest extends FakeProxyTestCase
{
    private function caseDir(): string
    {
        return __DIR__ . '/fixtures/datadir/one-case';
    }

    private function runCase(): void
    {
        $test = new class ('testDatadir') extends DatadirTestCase {
            use VcrDatadirTestTrait;

            protected function getScript(): string
            {
                return __DIR__ . '/fixtures/component.php';
            }

            public function prepare(): void
            {
                $this->setUp();
            }
        };
        $test->prepare();
        $spec = new DatadirTestSpecification($this->caseDir() . '/source/data', 0, null, null, null);
        try {
            $test->testDatadir($spec);
        } finally {
            $test->vcrStopCase(); // PHPUnit calls it as an @after hook
        }
    }

    public function testStartsTheCaseCassetteAndStops(): void
    {
        putenv('VCR_CONTROL_URL=' . $this->startFakeProxy());
        $this->runCase();

        $calls = $this->calls();
        self::assertSame(['/start', '/status', '/stop'], array_column($calls, 'path'));
        self::assertSame(
            (string) realpath($this->caseDir() . '/source/data') . '/cassettes/requests.json',
            $calls[0]['body']['cassette']
        );
    }

    public function testFailsWhenReplayHadUnmatchedRequests(): void
    {
        $stop = (string) json_encode([
            'mode' => 'replay',
            'cassette' => '/x/requests.json',
            'unmatched' => ['GET https://api.example.com/items (no recorded interaction matches)'],
            'unused' => 0,
        ]);
        putenv('VCR_CONTROL_URL=' . $this->startFakeProxy($stop));
        try {
            $this->runCase();
            self::fail('expected the case to fail');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('1 request(s) had no recorded answer', $e->getMessage());
            self::assertStringContainsString('GET https://api.example.com/items', $e->getMessage());
        }
    }

    public function testFailsRightAfterTheRunWhenReplayMissed(): void
    {
        $status = (string) json_encode([
            'mode' => 'replay',
            'active' => true,
            'cassette' => '/x/requests.json',
            'unmatched' => ['POST https://api.example.com/batch (no recorded interaction matches)'],
            'errors' => [],
        ]);
        putenv('VCR_CONTROL_URL=' . $this->startFakeProxy(null, $status));
        try {
            $this->runCase();
            self::fail('expected the case to fail');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('POST https://api.example.com/batch', $e->getMessage());
        }
        // the case is still ended
        self::assertSame(['/start', '/status', '/stop'], array_column($this->calls(), 'path'));
    }

    public function testFailsWhenRecordingRefusedToWrite(): void
    {
        $stop = (string) json_encode(['mode' => 'record', 'unmatched' => [], 'error' => 'Refusing to write']);
        putenv('VCR_CONTROL_URL=' . $this->startFakeProxy($stop));
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('VCR proxy: Refusing to write');
        $this->runCase();
    }

    public function testDoesNothingWithoutProxy(): void
    {
        putenv('VCR_CONTROL_URL');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'vcr-log');
        $this->runCase();
        self::assertSame([], $this->calls());
    }
}
