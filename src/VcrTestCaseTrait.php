<?php

declare(strict_types=1);

namespace Keboola\VcrTests;

use ReflectionClass;

/**
 * Gives each PHPUnit test method its own cassette in the keboola.vcr recording proxy.
 *
 * For tests that call the API from the PHPUnit process (or run the component with Symfony Process), not through
 * datadir cases. Use it in any PHPUnit TestCase:
 *
 *     class FunctionalTest extends TestCase
 *     {
 *         use VcrTestCaseTrait;
 *     }
 *
 * The cassette starts before setUp() and ends after tearDown(), so live set-up and clean-up calls of the test are
 * recorded and replayed with it. The cassette of a test is
 * <directory of the test class>/cassettes/<ShortClassName>/<method>[@<data set>].json
 * (override vcrCassetteDirectory() to move it). Without VCR_CONTROL_URL in the environment the trait does nothing
 * and the tests run live. Do not combine it with VcrDatadirTestTrait in one class.
 */
trait VcrTestCaseTrait
{
    /** @var VcrControlClient|null */
    private $vcrTestClient = null;

    /** @var bool */
    private $vcrTestActive = false;

    /**
     * Starts the test's cassette. Runs before setUp().
     *
     * @before
     */
    #[\PHPUnit\Framework\Attributes\Before]
    public function vcrStartTest(): void
    {
        $this->vcrTestClient = VcrControlClient::fromEnvironment();
        if ($this->vcrTestClient === null) {
            return;
        }
        $this->vcrTestClient->start($this->vcrCassettePathForTest());
        $this->vcrTestActive = true;
    }

    /**
     * Ends the test's cassette: record mode writes it here. Runs after tearDown(). Fails the test when replay saw a
     * request with no recorded answer, or when the proxy refused to write the recording.
     *
     * @after
     */
    #[\PHPUnit\Framework\Attributes\After]
    public function vcrStopTest(): void
    {
        if (!$this->vcrTestActive || $this->vcrTestClient === null) {
            return;
        }
        $this->vcrTestActive = false;
        $result = $this->vcrTestClient->stop();
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
                'Record the test again (VCR_MODE=record) or adjust the matching rules in tests/vcr.json.',
                count($unmatched),
                (string) ($result['cassette'] ?? 'the cassette'),
                implode("\n - ", array_map('strval', $unmatched))
            ));
        }
    }

    /**
     * Directory that holds this class's cassettes. Must be inside the tests directory that is mounted at the same
     * path in the test container and the proxy container.
     */
    protected function vcrCassetteDirectory(): string
    {
        $file = (new ReflectionClass(static::class))->getFileName();
        return dirname((string) $file) . '/cassettes';
    }

    protected function vcrCassettePathForTest(): string
    {
        $class = (new ReflectionClass(static::class))->getShortName();
        // PHPUnit 10+ has name(); PHPUnit 9 has getName(false). Both have dataName().
        $method = method_exists($this, 'name') ? $this->name() : $this->getName(false);
        $dataName = (string) $this->dataName();
        $file = $method . ($dataName !== '' ? '@' . $dataName : '');
        return $this->vcrCassetteDirectory() . '/' . $class . '/' . self::vcrSafeFileName($file) . '.json';
    }

    /**
     * True when the proxy replays recorded answers (offline).
     */
    protected function vcrIsReplay(): bool
    {
        return VcrControlClient::isReplay();
    }

    /**
     * Keeps a test live-only: it is skipped when the proxy replays. Call it first in the test or in setUp().
     */
    protected function vcrSkipInReplay(string $reason): void
    {
        if ($this->vcrIsReplay()) {
            $this->markTestSkipped('Live-only test, skipped in VCR replay: ' . $reason);
        }
    }

    private static function vcrSafeFileName(string $name): string
    {
        $safe = (string) preg_replace('~[^A-Za-z0-9@._-]+~', '_', $name);
        return trim($safe, '_') !== '' ? $safe : 'test';
    }
}
