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
 * The case starts in getTempDatadir(), is checked after each runScript() and ends after the test (record mode writes
 * the cassette then). The cassette of a case is <case>/source/data/cassettes/requests.json, the same place keboola.datadirtest uses
 * for Python components. Without VCR_CONTROL_URL in the environment the trait does nothing and the tests run live.
 * The component process inherits HTTPS_PROXY/HTTP_PROXY/NO_PROXY from the test container environment.
 */
trait VcrDatadirTestTrait
{
    /** @var VcrControlClient|null */
    private $vcrClient = null;

    /** @var bool */
    private $vcrCaseActive = false;

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
     * Checks the case right after each component run, so a replay miss fails with the request that had no recorded
     * answer, which is clearer than whatever error the component made of the proxy's answer. The case stays open:
     * a test may run the component more than once.
     */
    protected function runScript(string $datadirPath, ?string $runId = null): Process
    {
        $process = parent::runScript($datadirPath, $runId);
        if ($this->vcrCaseActive && $this->vcrClient !== null) {
            $this->vcrAssertClean($this->vcrClient->status());
        }
        return $process;
    }

    /**
     * Ends the case: record mode writes the cassette here.
     *
     * @after
     */
    #[\PHPUnit\Framework\Attributes\After]
    public function vcrStopCase(): void
    {
        if (!$this->vcrCaseActive || $this->vcrClient === null) {
            return;
        }
        $this->vcrCaseActive = false;
        $this->vcrAssertClean($this->vcrClient->stop());
    }

    /**
     * @param array<string, mixed> $result answer of /status or /stop
     */
    private function vcrAssertClean(array $result): void
    {
        $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : [];
        if (isset($result['error'])) {
            $errors[] = $result['error'];
        }
        if ($errors !== []) {
            $this->fail('VCR proxy: ' . implode('; ', array_map('strval', $errors)));
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
}
