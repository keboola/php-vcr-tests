<?php

declare(strict_types=1);

namespace Keboola\VcrTests;

use Keboola\DatadirTests\DatadirTestSpecificationInterface;
use Keboola\Temp\Temp;
use Symfony\Component\Process\Process;

/**
 * Gives each datadir test case its own cassette in the keboola.vcr recording proxy.
 *
 * Use it in a class that extends Keboola\DatadirTests\AbstractDatadirTestCase (datadir-tests 5.x or 6.x):
 *
 *     class DatadirTest extends DatadirTestCase
 *     {
 *         use VcrDatadirTestTrait;
 *     }
 *
 * The cassette of a case is <case>/source/data/cassettes/requests.json, the same place keboola.datadirtest uses
 * for Python components. Without VCR_CONTROL_URL in the environment the trait does nothing and the tests run live.
 * The component process inherits HTTPS_PROXY/HTTP_PROXY/NO_PROXY from the test container environment.
 */
trait VcrDatadirTestTrait
{
    private ?VcrControlClient $vcrClient = null;

    private bool $vcrCaseActive = false;

    public static function vcrCassettePath(string $sourceDatadirDirectory): string
    {
        return rtrim($sourceDatadirDirectory, '/') . '/cassettes/requests.json';
    }

    protected function getTempDatadir(DatadirTestSpecificationInterface $specification): Temp
    {
        $this->vcrClient = VcrControlClient::fromEnvironment();
        $source = $specification->getSourceDatadirDirectory();
        if ($this->vcrClient !== null && $source !== null) {
            $path = realpath($source) ?: $source;
            $this->vcrClient->start(self::vcrCassettePath($path));
            $this->vcrCaseActive = true;
        }
        return parent::getTempDatadir($specification);
    }

    /**
     * Ends the cassette right after the component ran, and fails the case when replay saw a request with no
     * recorded answer. That message is clearer than whatever error the component made of the proxy's answer.
     */
    protected function runScript(string $datadirPath, ?string $runId = null): Process
    {
        $process = parent::runScript($datadirPath, $runId);
        if ($this->vcrCaseActive && $this->vcrClient !== null) {
            $this->vcrCaseActive = false;
            $result = $this->vcrClient->stop();
            if (isset($result['error'])) {
                $this->fail('VCR proxy: ' . (string) $result['error']);
            }
            $unmatched = isset($result['unmatched']) && is_array($result['unmatched']) ? $result['unmatched'] : [];
            if ($unmatched !== []) {
                $this->fail(sprintf(
                    "VCR replay: %d request(s) had no recorded answer in %s:\n - %s\n" .
                    'Record the case again (VCR_MODE=record) or adjust the matching rules in tests/vcr.json.',
                    count($unmatched),
                    (string) ($result['cassette'] ?? 'the cassette'),
                    implode("\n - ", array_map('strval', $unmatched))
                ));
            }
        }
        return $process;
    }
}
