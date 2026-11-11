<?php

declare(strict_types=1);

/**
 * Stand-in for the NetSapiens API, used by tests/http.php only:
 *
 *   php -S 127.0.0.1:<port> tests/fixtures/mock-api.php
 *
 * Knows one auto attendant per extension (the fixture) and accepts every SMS,
 * except for user 7999. Extension 7998 answers with a redirect. Every request is
 * appended to the file named by the environment variable AATOSMS_MOCK_LOG, one
 * JSON object per line.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

const MOCK_TOKEN = 'nss_HTTPtest-0123456789abcdef';

$method = (string) $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

$log = getenv('AATOSMS_MOCK_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'method' => $method,
        'path' => $path,
        'authorization' => $authorization,
        'accept' => (string) ($_SERVER['HTTP_ACCEPT'] ?? ''),
        'content_type' => (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'body' => $body,
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

$answer = static function (int $status, array|string $payload): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo is_string($payload) ? $payload : json_encode($payload);
};

if ($authorization !== 'Bearer ' . MOCK_TOKEN) {
    $answer(401, ['code' => 401, 'message' => 'Unauthorized']);
} elseif ($method === 'GET' && preg_match('~\A/ns-api/v2/domains/([^/]+)/users/([^/]+)/autoattendants/([^/]+)\z~', $path, $match) === 1) {
    $expected = 'Prompt_' . $match[2] . (strlen($match[2]) === 3 ? '001' : '01');
    if ($match[2] === '7998') {
        // A redirect to an address that would work. The application must not follow it.
        header('Location: /ns-api/v2/domains/1180.DynaCloud/users/9000/autoattendants/Prompt_900001');
        $answer(302, ['code' => 302, 'message' => 'Found']);
    } elseif ($match[1] === '1180.DynaCloud' && $match[3] === $expected) {
        $answer(200, (string) file_get_contents(__DIR__ . '/autoattendant.json'));
    } else {
        $answer(404, ['code' => 404, 'message' => 'Not found']);
    }
} elseif ($method === 'POST' && preg_match('~\A/ns-api/v2/domains/([^/]+)/users/([^/]+)/messages\z~', $path, $match) === 1) {
    $message = json_decode($body, true);
    usleep(300000); // a real API takes a moment; this also lets two requests for one call overlap
    if (!is_array($message) || ($message['type'] ?? '') !== 'sms' || ($message['message'] ?? '') === ''
        || preg_match('/\A1[0-9]{10}\z/', (string) ($message['destination'] ?? '')) !== 1
        || preg_match('/\A1[0-9]{10}\z/', (string) ($message['from-number'] ?? '')) !== 1) {
        $answer(400, ['code' => 400, 'message' => 'Invalid message']);
    } elseif ($match[2] === '7999') {
        $answer(403, ['code' => 403, 'message' => 'from-number is not an SMS number of user 7999']);
    } else {
        $answer(200, ['code' => 202, 'message' => 'Accepted']);
    }
} else {
    $answer(404, ['code' => 404, 'message' => 'Not found']);
}
