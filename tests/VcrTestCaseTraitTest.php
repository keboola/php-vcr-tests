<?php

declare(strict_types=1);

namespace Keboola\VcrTests\Tests;

use Symfony\Component\Process\Process;

class VcrTestCaseTraitTest extends FakeProxyTestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/hook/HookExample.php';

    /**
     * @param array<string, string> $env
     */
    private function runFixture(array $env, ?string $filter = null): Process
    {
        $command = [PHP_BINARY, __DIR__ . '/../vendor/bin/phpunit', '--no-configuration',
            '--bootstrap', __DIR__ . '/../vendor/autoload.php'];
        if ($filter !== null) {
            $command[] = '--filter';
            $command[] = $filter;
        }
        $command[] = self::FIXTURE;
        $process = new Process($command, null, $env + ['FAKE_VCR_LOG' => $this->logFile]);
        $process->run();
        return $process;
    }

    /**
     * @return list<string> the fake proxy log as "path" or "marker:<where>"
     */
    private function sequence(): array
    {
        return array_map(
            fn(array $c) => $c['path'] === 'marker' ? 'marker:' . $c['body'] : (string) $c['path'],
            $this->calls()
        );
    }

    public function testCassetteWrapsSetUpTestAndTearDown(): void
    {
        $url = $this->startFakeProxy();
        $process = $this->runFixture(['VCR_CONTROL_URL' => $url, 'VCR_MODE' => 'record'], 'testPlain');
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        self::assertSame(
            ['/start', 'marker:setUp', '/status', 'marker:test', '/status', 'marker:tearDown', '/status', '/stop'],
            $this->sequence()
        );
        $expected = (string) realpath(__DIR__ . '/fixtures/hook') . '/cassettes/HookExample/testPlain.json';
        self::assertSame($expected, $this->calls()[0]['body']['cassette']);
    }

    public function testDataSetNamesBecomeSafeFileNames(): void
    {
        $url = $this->startFakeProxy();
        $process = $this->runFixture(['VCR_CONTROL_URL' => $url, 'VCR_MODE' => 'record'], 'testWithData');
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        $cassettes = array_values(array_map(
            fn(array $c) => basename((string) $c['body']['cassette']),
            array_filter($this->calls(), fn(array $c) => $c['path'] === '/start')
        ));
        self::assertSame(['testWithData@first_case.json', 'testWithData@second_case.json'], $cassettes);
    }

    public function testUnmatchedRequestFailsTheTest(): void
    {
        $stop = (string) json_encode([
            'mode' => 'replay',
            'cassette' => '/x/testPlain.json',
            'unmatched' => ['GET https://api.example.com/items (no recorded interaction matches)'],
        ]);
        $url = $this->startFakeProxy($stop);
        $process = $this->runFixture(['VCR_CONTROL_URL' => $url, 'VCR_MODE' => 'replay'], 'testPlain');
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('GET https://api.example.com/items', $process->getOutput());
    }

    public function testLiveOnlyTestIsSkippedInReplay(): void
    {
        $url = $this->startFakeProxy();
        $process = $this->runFixture(['VCR_CONTROL_URL' => $url, 'VCR_MODE' => 'replay'], 'testLiveOnly');
        self::assertSame(0, $process->getExitCode(), $process->getOutput());
        self::assertStringContainsString('needs the live API', $process->getOutput() . $this->verboseSkips($url));
        self::assertNotContains('marker:live-only', $this->sequence());
    }

    public function testDoesNothingWithoutProxy(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'vcr-log');
        $process = $this->runFixture([], 'testPlain');
        self::assertSame(0, $process->getExitCode(), $process->getOutput());
        self::assertSame([], $this->calls());
    }

    private function verboseSkips(string $url): string
    {
        // PHPUnit 9 prints skip reasons only with --verbose; 10+ prints them with --display-skipped.
        $flag = class_exists('PHPUnit\Runner\Version') && version_compare(\PHPUnit\Runner\Version::id(), '10', '>=')
            ? '--display-skipped' : '--verbose';
        $process = new Process([PHP_BINARY, __DIR__ . '/../vendor/bin/phpunit', '--no-configuration', $flag,
            '--bootstrap', __DIR__ . '/../vendor/autoload.php', '--filter', 'testLiveOnly', self::FIXTURE],
            null, ['VCR_CONTROL_URL' => $url, 'VCR_MODE' => 'replay', 'FAKE_VCR_LOG' => $this->logFile]);
        $process->run();
        return $process->getOutput();
    }
}
