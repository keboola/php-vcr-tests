<?php

declare(strict_types=1);

namespace Keboola\VcrTests;

use RuntimeException;

/**
 * Talks to the control port of the keboola.vcr recording proxy (python -m keboola.vcr.proxy).
 *
 * Uses PHP streams on purpose: they ignore HTTPS_PROXY/HTTP_PROXY, so the control call never loops through the
 * proxy it controls.
 */
final class VcrControlClient
{
    public const ENV_CONTROL_URL = 'VCR_CONTROL_URL';
    public const ENV_MODE = 'VCR_MODE';

    private string $baseUrl;

    private float $timeout;

    public function __construct(string $baseUrl, float $timeout = 30.0)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * The client for the proxy named by VCR_CONTROL_URL, or null when the tests run without the proxy (live).
     */
    public static function fromEnvironment(): ?self
    {
        $url = getenv(self::ENV_CONTROL_URL);
        return is_string($url) && $url !== '' ? new self($url) : null;
    }

    /**
     * "record", "replay", or "live" when no proxy is configured.
     */
    public static function mode(): string
    {
        if (self::fromEnvironment() === null) {
            return 'live';
        }
        $mode = getenv(self::ENV_MODE);
        return is_string($mode) && $mode !== '' ? $mode : 'replay';
    }

    public static function isReplay(): bool
    {
        return self::mode() === 'replay';
    }

    /**
     * @return array<string, mixed>
     */
    public function start(string $cassettePath): array
    {
        return $this->post('/start', ['cassette' => $cassettePath]);
    }

    /**
     * The active case so far, without ending it.
     *
     * @return array<string, mixed> with "unmatched" (requests with no recorded answer) and "errors" (recording)
     */
    public function status(): array
    {
        return $this->call('GET', '/status', null);
    }

    /**
     * @return array<string, mixed> with "unmatched" (list of requests with no recorded answer) and, on error, "error"
     */
    public function stop(): array
    {
        return $this->post('/stop', []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->call('POST', $path, (string) json_encode($payload === [] ? new \stdClass() : $payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?string $body): array
    {
        $options = ['method' => $method, 'timeout' => $this->timeout, 'ignore_errors' => true];
        if ($body !== null) {
            $options['header'] = "Content-Type: application/json\r\nContent-Length: " . strlen($body);
            $options['content'] = $body;
        }
        $context = stream_context_create(['http' => $options]);
        $url = $this->baseUrl . $path;
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException(sprintf('VCR proxy control call failed: cannot reach %s', $url));
        }
        /** @var string[] $http_response_header set by the http stream wrapper */
        $statusLine = $http_response_header[0] ?? '';
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('VCR proxy control call %s: invalid answer (%s)', $path, $statusLine));
        }
        if (strpos($statusLine, ' 200') === false) {
            throw new RuntimeException(sprintf(
                'VCR proxy control call %s failed (%s): %s',
                $path,
                $statusLine,
                (string) ($decoded['error'] ?? $response)
            ));
        }
        return $decoded;
    }
}
