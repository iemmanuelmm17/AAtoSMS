<?php

declare(strict_types=1);

/**
 * HTTPS stand-in for the NetSapiens API, used by tests/http.php only to check
 * how the application treats server certificates:
 *
 *   php tests/fixtures/tls-server.php <port> <certificate+key.pem> <request log>
 *
 * Answers every request with the auto attendant fixture and appends the first
 * line and the Authorization header of each request to the log, so a test can
 * prove that nothing was sent to a server whose certificate was refused.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[$port, $certificate, $log] = array_slice($argv, 1) + ['', '', ''];
$server = stream_socket_server(
    'tls://127.0.0.1:' . (int) $port,
    $errorCode,
    $errorText,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create(['ssl' => ['local_cert' => $certificate]])
);
if ($server === false) {
    fwrite(STDERR, 'tls-server: ' . $errorText . PHP_EOL);
    exit(1);
}

$body = (string) file_get_contents(__DIR__ . '/autoattendant.json');
while (true) {
    // The TLS handshake happens here; it fails when the client refuses the certificate.
    $client = @stream_socket_accept($server, 3600);
    if ($client === false) {
        usleep(10000);
        continue;
    }
    stream_set_timeout($client, 5);
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && ($line = fgets($client)) !== false) {
        $head .= $line;
    }
    if ($head !== '') {
        preg_match('/^Authorization:\s*(.*?)\s*$/mi', $head, $authorization);
        file_put_contents($log, trim((string) strtok($head, "\r\n")) . ' | ' . ($authorization[1] ?? '') . "\n", FILE_APPEND | LOCK_EX);
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    }
    fclose($client);
}
