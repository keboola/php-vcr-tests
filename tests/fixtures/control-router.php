<?php

declare(strict_types=1);

// Fake control port of the recording proxy, for `php -S`. Logs each call to $FAKE_VCR_LOG and answers /stop with
// the JSON in $FAKE_VCR_STOP (default: no unmatched requests).

$log = (string) getenv('FAKE_VCR_LOG');
$uri = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
file_put_contents($log, json_encode(['path' => $uri, 'body' => json_decode($body, true)]) . "\n", FILE_APPEND);

header('Content-Type: application/json');
if ($uri === '/start') {
    $payload = json_decode($body, true);
    if (!is_array($payload) || !isset($payload['cassette'])) {
        http_response_code(400);
        echo json_encode(['error' => 'missing cassette']);
        return;
    }
    echo json_encode(['mode' => 'replay', 'cassette' => $payload['cassette'], 'interactions' => 1]);
    return;
}
if ($uri === '/stop') {
    echo getenv('FAKE_VCR_STOP') ?: json_encode(['mode' => 'replay', 'unmatched' => [], 'unused' => 0]);
    return;
}
http_response_code(404);
echo json_encode(['error' => 'unknown endpoint']);
