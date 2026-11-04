<?php

declare(strict_types=1);

namespace Keboola\VcrTests\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

abstract class FakeProxyTestCase extends TestCase
{
    protected string $logFile;

    private ?Process $server = null;

    /**
     * Starts the fake control port; $stopAnswer is what /stop returns (JSON).
     */
    protected function startFakeProxy(?string $stopAnswer = null, ?string $statusAnswer = null): string
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'vcr-log');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        assert($socket !== false);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $env = ['FAKE_VCR_LOG' => $this->logFile];
        if ($stopAnswer !== null) {
            $env['FAKE_VCR_STOP'] = $stopAnswer;
        }
        if ($statusAnswer !== null) {
            $env['FAKE_VCR_STATUS'] = $statusAnswer;
        }
        $this->server = new Process(['php', '-S', $address, __DIR__ . '/fixtures/control-router.php'], null, $env);
        $this->server->start();
        $url = 'http://' . $address;
        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', (int) substr($address, strrpos($address, ':') + 1));
            if ($conn !== false) {
                fclose($conn);
                return $url;
            }
            usleep(100000);
        }
        self::fail('fake proxy did not start');
    }

    /**
     * @return array<int, array{path: string, body: mixed}>
     */
    protected function calls(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->logFile)));
        return array_values(array_map(fn(string $l) => json_decode($l, true), $lines));
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop(0);
        }
        putenv('VCR_CONTROL_URL');
        putenv('VCR_MODE');
        parent::tearDown();
    }
}
