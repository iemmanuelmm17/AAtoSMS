<?php

declare(strict_types=1);

/**
 * Whole calls over real HTTP:  php tests/http.php
 *
 * Starts PHP built-in web servers on this computer - the application twice
 * (public/index.php with a test .env; two processes that share one storage
 * folder, like the PHP processes of a real web server) and a stand-in for the
 * NetSapiens API - and then makes calls the way the PBX does. Nothing leaves
 * the computer and no real text is sent. Needs the cURL extension and proc_open.
 *
 * tests/run.php covers the behaviour in detail without any server; this script
 * proves that the same works through a web server, PHP's cURL and files on disk.
 *
 * Where PHP cannot start programs, servers that are already running can be used
 * (see settings() and startServer() below for how they have to be started):
 *   php tests/http.php --app=http://127.0.0.1:8091 [--app2=http://127.0.0.1:8092] --dir=<their folder>
 */

namespace AAtoSMS\Tests;

use AAtoSMS\ApiException;
use AAtoSMS\Config;
use AAtoSMS\NetSapiens;
use AAtoSMS\Response;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return true;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support.php';

const HTTP_KEY = 'http-test-key-0123456789-abcdefgh';
const HTTP_TOKEN = 'nss_HTTPtest-0123456789abcdef'; // the one tests/fixtures/mock-api.php accepts

/** What the core adds to every request. */
const PBX = [
    'NmsAni' => '8452632561',
    'NmsDnis' => '8457747000',
    'AccountUser' => '9000',
    'AccountDomain' => '1180.DynaCloud',
    'AccountLastDial' => '8457747000',
    'OrigCallID' => 'http-test-orig',
    'TermCallID' => 'http-test-term',
    'ToUser' => '9000',
    'ToDomain' => '1180.DynaCloud',
];

/** Settings file of the application under test. */
function settings(string $folder, int $apiPort): string
{
    return implode("\r\n", [
        '# written by tests/http.php',
        'NS_API_URL=http://127.0.0.1:' . $apiPort . '/ns-api/v2',
        'NS_API_TOKEN=' . HTTP_TOKEN,
        'WEBRESPONDER_KEY=' . HTTP_KEY,
        'STORAGE_PATH=' . $folder . '/var',
        'CALLER_ID_DIGIT_STYLE=commas',
        'LOG_LEVEL=debug',
        '',
    ]);
}

/** @return list<int> ports that are free right now */
function freePorts(int $count): array
{
    $sockets = [];
    $ports = [];
    for ($i = 0; $i < $count; $i++) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorText);
        if ($socket === false) {
            throw new \RuntimeException('No free port: ' . $errorText);
        }
        $sockets[] = $socket;
        $ports[] = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    }
    array_map('fclose', $sockets);

    return $ports;
}

/**
 * Starts "php -S ..." in the background.
 *
 * @param list<string>          $arguments
 * @param array<string, string> $environment added to the environment of this process
 * @return resource
 */
function startServer(array $arguments, string $logFile, array $environment)
{
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open(
        array_merge([PHP_BINARY !== '' ? PHP_BINARY : 'php'], $arguments),
        [0 => ['file', $null, 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes,
        dirname(__DIR__),
        array_merge(getenv(), $environment)
    );
    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start: php ' . implode(' ', $arguments));
    }

    return $process;
}

/**
 * Starts "php -S 127.0.0.1:<free port> ..." and waits until it answers.
 *
 * A port that was free a moment ago can be taken by the time the server asks for it, and a
 * busy computer can be slow to start a program. So a start that fails is repeated on another
 * port before the tests give up.
 *
 * @param list<string>          $arguments   what follows the address: "-t <folder>", or a router script
 * @param array<string, string> $environment added to the environment of this process
 * @param string                $path        address that shows that the server works
 * @return array{0:resource, 1:int} the process and its port
 */
function serve(array $arguments, string $logFile, array $environment, string $path): array
{
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        [$port] = freePorts(1);
        $process = startServer(array_merge(['-S', '127.0.0.1:' . $port], $arguments), $logFile, $environment);
        $deadline = microtime(true) + 20;
        do {
            usleep(50000);
            $running = proc_get_status($process)['running'];
            if (!$running) {
                break; // it ends at once when it does not get the port
            }
            // Asked briefly and often: Windows takes two seconds to say that nothing listens yet.
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorText, 0.2);
            if ($socket === false) {
                continue;
            }
            fclose($socket);
            if (answers('http://127.0.0.1:' . $port . $path, 0) && proc_get_status($process)['running']) {
                return [$process, $port];
            }
        } while (microtime(true) < $deadline);
        stopServer($process);
        @file_put_contents($logFile, sprintf("(start %d: no answer on port %d, the program %s)\n", $attempt, $port, $running ? 'was still running' : 'had ended'), FILE_APPEND);
    }

    throw new \RuntimeException(sprintf("php -S 127.0.0.1:<port> %s did not start.\n%s", implode(' ', $arguments), (string) @file_get_contents($logFile)));
}

/** Ends the tests before the first one: without servers there is nothing to test. */
function giveUp(string $message): never
{
    fwrite(STDERR, $message . "\n");
    if (getenv('GITHUB_ACTIONS') === 'true') {
        echo '::error::', str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $message), "\n";
    }
    exit(1);
}

/** @param resource $process */
function stopServer($process): void
{
    proc_terminate($process);
    for ($i = 0; $i < 30 && proc_get_status($process)['running']; $i++) {
        usleep(100000);
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
}

/**
 * One HTTP request. $form is sent as a form-encoded body.
 *
 * @param list<string> $send extra request headers
 */
function http(string $method, string $url, ?array $form = null, array $send = []): Response
{
    $handle = prepare($method, $url, $form, $headers);
    curl_setopt($handle, CURLOPT_HTTPHEADER, $send);
    $body = curl_exec($handle);
    if (!is_string($body)) {
        throw new Failure(sprintf('%s %s failed: %s', $method, $url, curl_error($handle)));
    }

    return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, $headers);
}

/**
 * @param array<string, string>|null $headers filled with the response headers while the request runs
 */
function prepare(string $method, string $url, ?array $form, ?array &$headers): \CurlHandle
{
    $headers = [];
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }

            return strlen($line);
        },
    ]);
    if ($form !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form));
    }

    return $handle;
}

/** Waits until a server answers; false after $seconds. */
function answers(string $url, int $seconds = 30): bool
{
    $deadline = microtime(true) + $seconds;
    do {
        try {
            http('GET', $url);

            return true;
        } catch (Failure) {
            usleep(250000);
        }
    } while (microtime(true) < $deadline);

    return false;
}

/** Waits until something listens on a local port; false after $seconds. */
function listens(int $port, int $seconds = 15): bool
{
    $deadline = microtime(true) + $seconds;
    do {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorText, 1.0);
        if ($socket !== false) {
            fclose($socket);

            return true;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);

    return false;
}

/**
 * Makes a self-signed certificate for "localhost" in $folder:
 * server.pem (certificate and key, for the server) and ca.pem (certificate only, to trust it).
 */
function makeCertificate(string $folder): void
{
    // An own configuration file, because the OpenSSL of Windows PHP often cannot find one.
    $config = $folder . '/openssl.cnf';
    file_put_contents($config, implode("\n", [
        '[req]', 'distinguished_name = dn', 'x509_extensions = ext', 'prompt = no',
        '[dn]', 'CN = localhost',
        '[ext]', 'subjectAltName = DNS:localhost', 'basicConstraints = critical,CA:TRUE', '',
    ]));
    $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'ext'];
    $key = openssl_pkey_new($options);
    $request = $key === false ? false : openssl_csr_new(['commonName' => 'localhost'], $key, $options);
    $certificate = $request === false ? false : openssl_csr_sign($request, null, $key, 2, $options);
    if ($certificate === false || !openssl_x509_export($certificate, $certificatePem) || !openssl_pkey_export($key, $keyPem, null, $options)) {
        throw new \RuntimeException('Could not make a test certificate: ' . openssl_error_string());
    }
    file_put_contents($folder . '/server.pem', $certificatePem . $keyPem);
    file_put_contents($folder . '/ca.pem', $certificatePem);
}

// ---------------------------------------------------------------------------
// Servers
// ---------------------------------------------------------------------------

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/\A--(app|app2|dir)=(.+)\z/', $argument, $match) !== 1) {
        fwrite(STDERR, "Usage: php tests/http.php [--app=http://127.0.0.1:8091 [--app2=http://127.0.0.1:8092] --dir=<folder>]\n");
        exit(2);
    }
    $options[$match[1]] = $match[2];
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "The cURL extension is needed for this test.\n");
    exit(2);
}

/** @var array<string, resource> $servers */
$servers = [];
$ownServers = !isset($options['app']);
if ($ownServers) {
    if (!function_exists('proc_open')) {
        fwrite(STDERR, "proc_open is disabled, so the test servers cannot be started.\n");
        exit(2);
    }
    $folder = temporaryFolder();
    try {
        [$servers['api'], $apiPort] = serve(
            [__DIR__ . '/fixtures/mock-api.php'],
            $folder . '/api-server.log',
            ['AATOSMS_MOCK_LOG' => $folder . '/api.jsonl'],
            '/'
        );
        file_put_contents($folder . '/.env', settings($folder, $apiPort));
        $ports = [];
        foreach (['app', 'app2'] as $name) {
            [$servers[$name], $ports[$name]] = serve(
                ['-t', dirname(__DIR__) . '/public'],
                $folder . '/' . $name . '-server.log',
                ['AATOSMS_ENV_FILE' => $folder . '/.env'],
                '/index.php'
            );
        }
    } catch (\RuntimeException $error) {
        array_map(stopServer(...), $servers);
        usleep(200000); // Windows: let the servers release their log files
        removeFolder($folder);
        giveUp('The test servers did not start. ' . $error->getMessage());
    }
    $app = 'http://127.0.0.1:' . $ports['app'];
    $second = 'http://127.0.0.1:' . $ports['app2'];
    file_put_contents($folder . '/api.jsonl', ''); // forget the request that waited for the stand-in API
} else {
    $folder = rtrim($options['dir'] ?? '', '/\\');
    if (!is_file($folder . '/.env')) {
        fwrite(STDERR, "--dir must name the folder that holds the .env of the running application.\n");
        exit(2);
    }
    $app = rtrim($options['app'], '/');
    $second = rtrim($options['app2'] ?? $options['app'], '/');
    if (!answers($app . '/index.php') || !answers($second . '/index.php')) {
        giveUp('The application does not answer at ' . $app . '/index.php and ' . $second . '/index.php');
    }
}

$stop = static function () use (&$servers, $ownServers, $folder): void {
    foreach ($servers as $server) {
        stopServer($server);
    }
    if ($ownServers && is_dir($folder)) {
        if ($servers !== []) {
            usleep(200000); // Windows: let the servers release their log files
        }
        removeFolder($folder);
    }
    $servers = [];
};
register_shutdown_function($stop); // also when this script itself fails

$started = microtime(true);
$entry = $app . '/index.php';
$scriptPath = (string) parse_url($entry, PHP_URL_PATH); // "/index.php", or "/aatosms/index.php" behind a web server
$origin = substr($entry, 0, strlen($entry) - strlen($scriptPath));
$secondOrigin = substr($second, 0, strlen($second . '/index.php') - strlen((string) parse_url($second . '/index.php', PHP_URL_PATH)));

/** @return list<array<string, string>> requests received by the stand-in API so far */
$api = static function () use ($folder): array {
    $lines = is_file($folder . '/api.jsonl') ? file($folder . '/api.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines ?: []);
};
$texts = static fn (): array => array_values(array_map(
    static fn (array $request): array => json_decode($request['body'], true, 8, JSON_THROW_ON_ERROR),
    array_filter($api(), static fn (array $request): bool => $request['method'] === 'POST')
));
$log = static function () use ($folder): string {
    $text = '';
    foreach (glob($folder . '/var/logs/*.log') ?: [] as $file) {
        $text .= file_get_contents($file);
    }

    return $text;
};
/** Address the core would request after the <Gather> of $answer. */
$callback = static function (Response $answer) use ($origin): string {
    $action = gather($answer)['action'] ?? null;
    if ($action === null) {
        throw new Failure("the answer has no <Gather action>\n" . $answer->body);
    }

    return str_starts_with($action, '/') ? $origin . $action : $action;
};

// ---------------------------------------------------------------------------
// Calls
// ---------------------------------------------------------------------------

test('http: press 1 - the text goes to the caller, from the SMSNumber of the address', function () use ($entry, $scriptPath, $api, $texts, $callback): void {
    $menu = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'SMSNumber' => '8455550199'] + PBX + ['Digits' => '']));
    same(200, $menu->status);
    same('application/xml; charset=UTF-8', $menu->headers['Content-Type'] ?? null);
    same('no-store', $menu->headers['Cache-Control'] ?? null);
    same('To receive a text message at the number you are calling from, 8, 4, 5, 2, 6, 3, 2, 5, 6, 1, press 1. To enter a different mobile number, press 2.', spoken($menu), 'menu, with the digit style of .env');
    same(['input' => 'dtmf', 'numDigits' => '1', 'timeout' => '6', 'digitEndTimeout' => '5'], array_diff_key(gather($menu), ['action' => 1]));
    ok(preg_match('~\A' . preg_quote($scriptPath, '~') . '\?call=[a-f0-9]{32}\.1\z~', gather($menu)['action']) === 1, 'callback is the path of the script plus one parameter: ' . gather($menu)['action']);
    lacks(HTTP_KEY, $menu->body, 'answer');
    lacks(HTTP_TOKEN, $menu->body, 'answer');

    $read = $api()[0];
    same(['GET', '/ns-api/v2/domains/1180.DynaCloud/users/9000/autoattendants/Prompt_900001'], [$read['method'], $read['path']]);
    same(['Bearer ' . HTTP_TOKEN, 'application/json'], [$read['authorization'], $read['accept']]);
    ok(str_starts_with($read['user_agent'], 'AAtoSMS/'), 'user agent names the application');

    $begun = microtime(true);
    $done = http('GET', $callback($menu) . '&' . http_build_query(PBX + ['Digits' => '1']));
    same('Your text message has been sent. Thank you for calling. Goodbye.', spoken($done));
    ok(hangsUp($done), 'the call is hung up');
    ok(microtime(true) - $begun >= 0.25, 'the answer waited for the API');

    $sent = array_values(array_filter($api(), static fn (array $request): bool => $request['method'] === 'POST'));
    same(1, count($sent));
    same('/ns-api/v2/domains/1180.DynaCloud/users/9000/messages', $sent[0]['path']);
    same(['Bearer ' . HTTP_TOKEN, 'application/json', 'application/json'], [$sent[0]['authorization'], $sent[0]['accept'], $sent[0]['content_type']]);
    same(
        '{"type":"sms","message":"' . Bench::SCRIPT . '","destination":"18452632561","from-number":"18455550199"}',
        $sent[0]['body'],
        'request body'
    );

    same($done->body, http('GET', $callback($menu) . '&Digits=1')->body, 'a repeated request gets the same answer');
    same(1, count($texts()), 'and no second text');
});

test('http: POST from the core - press 2, type a number, confirm; sent from the dialed number', function () use ($entry, $texts, $callback): void {
    $menu = http('POST', $entry . '?key=' . HTTP_KEY, PBX + ['Digits' => '']);
    same(200, $menu->status);
    contains('calling from, 8, 4, 5, 2, 6, 3, 2, 5, 6, 1, press 1', spoken($menu));

    $enter = http('POST', $callback($menu), PBX + ['Digits' => '2']);
    same('Please enter the ten digit mobile number, starting with the area code.', spoken($enter));
    same('10', gather($enter)['numDigits']);

    $confirm = http('POST', $callback($enter), PBX + ['Digits' => '2015551234']);
    same('You entered 2, 0, 1, 5, 5, 5, 1, 2, 3, 4. To send the text message to this number, press 1. To enter a different number, press 2.', spoken($confirm));
    same(1, count($texts()), 'nothing is sent before the confirmation');

    $done = http('POST', $callback($confirm), PBX + ['Digits' => '1']);
    contains('has been sent', spoken($done));
    ok(hangsUp($done), 'the call is hung up');
    same(['type' => 'sms', 'message' => Bench::SCRIPT, 'destination' => '12015551234', 'from-number' => '18457747000'], $texts()[1]);
});

test('http: a number typed with the leading 1, then the pound key', function () use ($entry, $texts, $callback): void {
    $menu = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY] + PBX));
    $enter = http('GET', $callback($menu) . '&Digits=2');

    $wait = http('GET', $callback($enter) . '&Digits=1201555123');
    same('', spoken($wait), 'ten keys that start with 1: the last key is awaited in silence');
    same('1', gather($wait)['numDigits']);

    $confirm = http('GET', $callback($wait) . '&Digits=4');
    contains('You entered 2, 0, 1, 5, 5, 5, 1, 2, 3, 4.', spoken($confirm));

    $again = http('POST', $callback($confirm), PBX + ['Digits' => '#']);
    same(spoken($confirm), spoken($again), 'the pound key is not an answer: the question is asked again');
    same($again->body, http('GET', $callback($confirm) . '&Digits=%23')->body, 'a repeated request gets the same answer');
    same(2, count($texts()), 'nothing was sent');
});

test('http: a form body is read even when the PBX does not say it is a form', function () use ($entry): void {
    foreach (['Content-Type: text/plain', 'Content-Type:'] as $header) {
        $menu = http('POST', $entry . '?key=' . HTTP_KEY, PBX, [$header]);
        same(200, $menu->status);
        contains('calling from, 8, 4, 5, 2, 6, 3, 2, 5, 6, 1, press 1', spoken($menu), $header);
    }
});

test('http: silence and wrong keys repeat the prompt, then end the call', function () use ($entry, $texts, $callback): void {
    $answer = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY] + PBX));
    $answer = http('GET', $callback($answer) . '&Digits=');
    contains('Sorry, we did not receive a response. To receive a text message', spoken($answer));
    $answer = http('GET', $callback($answer) . '&Digits=9');
    contains('Sorry, that is not a valid selection. To receive a text message', spoken($answer));
    $answer = http('GET', $callback($answer) . '&Digits=');
    same('We did not receive a valid response. Goodbye.', spoken($answer));
    ok(hangsUp($answer), 'the call is hung up');
    same(2, count($texts()), 'no text');
});

test('http: requests that are refused', function () use ($entry): void {
    $wrongKey = http('GET', $entry . '?' . http_build_query(['key' => 'wrong'] + PBX));
    same(403, $wrongKey->status);
    same('Access denied.', spoken($wrongKey));
    same(403, http('GET', $entry)->status, 'no parameters at all');

    $put = http('PUT', $entry . '?key=' . HTTP_KEY);
    same([405, '', 'GET, POST'], [$put->status, $put->body, $put->headers['Allow'] ?? null]);

    $unknown = http('GET', $entry . '?call=' . str_repeat('0', 32) . '.1&Digits=1');
    same(200, $unknown->status);
    contains('We are sorry', spoken($unknown));
    ok(hangsUp($unknown), 'the call is hung up');
});

test('http: other auto attendants - prompt names follow the extension', function () use ($entry, $api): void {
    foreach (['8002' => 'Prompt_800201', '900' => 'Prompt_900001'] as $extension => $prompt) {
        $menu = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'AccountUser' => (string) $extension] + PBX));
        ok(gather($menu) !== null, 'menu for extension ' . $extension . ': ' . $menu->body);
        $paths = array_column($api(), 'path');
        same('/ns-api/v2/domains/1180.DynaCloud/users/' . $extension . '/autoattendants/' . $prompt, end($paths));
    }
});

test('http: a redirect from the API is not followed', function () use ($entry, $api, $log): void {
    $before = count($api());
    $answer = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'AccountUser' => '7998'] + PBX));
    contains('We are sorry', spoken($answer));
    ok(hangsUp($answer), 'the call is hung up');
    same(['/ns-api/v2/domains/1180.DynaCloud/users/7998/autoattendants/Prompt_799801'], array_column(array_slice($api(), $before), 'path'), 'requests to the API');
    contains('Call cannot start: ns-api GET /domains/1180.DynaCloud/users/7998/autoattendants/Prompt_799801 returned HTTP 302', $log());
});

test('http: the API refuses the text - apology, and its answer is in the log', function () use ($entry, $log, $callback): void {
    $menu = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'SMSUser' => '7999', 'NmsAni' => '2125550147'] + PBX));
    $answer = http('GET', $callback($menu) . '&Digits=1');
    contains('We are sorry, we are unable to send the text message', spoken($answer));
    ok(hangsUp($answer), 'the call is hung up');
    contains('Text not sent: ns-api POST /domains/1180.DynaCloud/users/7999/messages returned HTTP 403', $log());
    contains('from-number is not an SMS number of user 7999', $log());
});

test('http: the same step requested twice at the same moment, through two PHP processes, sends one text', function () use ($entry, $origin, $secondOrigin, $texts): void {
    $menu = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'NmsAni' => '6465550123'] + PBX));
    $action = (string) (gather($menu)['action'] ?? '');
    ok(str_starts_with($action, '/'), 'callback is a path on the same host: ' . $action);
    $before = count($texts());

    // The stand-in API takes 0.3 s to accept a text, so the two requests overlap.
    $multi = curl_multi_init();
    $handles = [
        prepare('GET', $origin . $action . '&Digits=1', null, $firstHeaders),
        prepare('GET', $secondOrigin . $action . '&Digits=1', null, $secondHeaders),
    ];
    foreach ($handles as $handle) {
        curl_multi_add_handle($multi, $handle);
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);
    $bodies = array_map(static fn (\CurlHandle $handle): string => (string) curl_multi_getcontent($handle), $handles);

    contains('has been sent', spoken($bodies[0]));
    same($bodies[0], $bodies[1], 'both requests get the same answer');
    same(1, count($texts()) - $before, 'texts sent');
});

test('http: calls that arrive together cannot exceed the limit per destination', function () use ($entry, $origin, $secondOrigin, $texts): void {
    // Six callers ask for a text to the same number at the same moment, through two PHP processes.
    $multi = curl_multi_init();
    $handles = [];
    foreach ([0, 1, 2, 3, 4, 5] as $n) {
        $menu = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'NmsAni' => '3125550188'] + PBX));
        $action = (string) (gather($menu)['action'] ?? '');
        ok(str_starts_with($action, '/'), 'callback is a path on the same host: ' . $action);
        $handles[$n] = prepare('GET', ($n % 2 === 0 ? $origin : $secondOrigin) . $action . '&Digits=1', null, $headers[$n]);
        curl_multi_add_handle($multi, $handles[$n]);
    }
    $before = count($texts());
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $heard = array_count_values(array_map(static fn (\CurlHandle $handle): string => spoken((string) curl_multi_getcontent($handle)), $handles));
    ksort($heard);
    same([
        'A text message was already sent to this number recently. Please check your messages, or try again later. Goodbye.' => 3,
        'Your text message has been sent. Thank you for calling. Goodbye.' => 3,
    ], $heard, 'what the six callers heard');
    same(3, count($texts()) - $before, 'texts sent (the limit is 3)');
});

test('http: repeat=yes in the address - after the text the call is forwarded back to the auto attendant', function () use ($entry, $texts, $callback, $log): void {
    // As the core sends it with <HttpMethod=POST>: its values in the body, ours in the address.
    $pbx = ['NmsAni' => '7185550142'] + PBX;
    $before = count($texts());
    $menu = http('POST', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'repeat' => 'yes']), $pbx + ['Digits' => '']);
    contains('calling from, 7, 1, 8, 5, 5, 5, 0, 1, 4, 2, press 1', spoken($menu));

    $done = http('POST', $callback($menu), $pbx + ['Digits' => '1']);
    same(200, $done->status);
    same('application/xml; charset=UTF-8', $done->headers['Content-Type'] ?? null);
    same('Your text message has been sent. Returning to the main menu.', spoken($done));
    same('sip:9000@1180.DynaCloud', forwardsTo($done), 'forwarded to the auto attendant: AccountUser in the AccountDomain of the request body');
    ok(!hangsUp($done), 'the call is not hung up');
    same(1, count($texts()) - $before, 'texts sent');
    same('17185550142', $texts()[$before]['destination']);

    same($done->body, http('POST', $callback($menu), $pbx + ['Digits' => '1'])->body, 'a repeated request gets the same answer');
    same(1, count($texts()) - $before, 'and no second text');
    contains('Forwarding the call back to the auto attendant {"to":"sip:9000@1180.DynaCloud"}', $log());

    // The same caller without repeat in the address is hung up, as before.
    $menu = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY] + $pbx));
    $done = http('GET', $callback($menu) . '&Digits=1');
    same('Your text message has been sent. Thank you for calling. Goodbye.', spoken($done));
    ok(hangsUp($done), 'the call is hung up');
    same(null, forwardsTo($done));
});

test('http: option=5 in the address - the message of key 5 is the text', function () use ($entry, $texts, $callback, $log): void {
    $pbx = ['NmsAni' => '9175550163'] + PBX;
    $before = count($texts());
    $menu = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'option' => '5'] + $pbx));
    contains('calling from, 9, 1, 7, 5, 5, 5, 0, 1, 6, 3, press 1', spoken($menu));

    $done = http('GET', $callback($menu) . '&Digits=1');
    contains('has been sent', spoken($done));
    same(1, count($texts()) - $before, 'texts sent');
    same(['type' => 'sms', 'message' => 'uploaded message', 'destination' => '19175550163', 'from-number' => '18457747000'], $texts()[$before]);
    contains('"option":"5","sms_user":"9000","from":"8457747000","caller":"9175550163"', $log(), 'log');

    // The PBX sending its values in the body: option stays in the address.
    $menu = http('POST', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'option' => '9']), $pbx + ['Digits' => '']);
    http('POST', $callback($menu), $pbx + ['Digits' => '1']);
    same('First part. Second part.', $texts()[$before + 1]['message']);

    $wrong = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'option' => '10'] + $pbx));
    contains('We are sorry', spoken($wrong));
    ok(hangsUp($wrong), 'a value that is not a key: the call is hung up');
    same(2, count($texts()) - $before, 'and nothing more is sent');
    contains('Call cannot start: option "10" is not an auto attendant key (0-9 or *).', $log(), 'log');
});

test('http: installation check through the web server', function () use ($entry, $scriptPath, $ownServers, $texts, $folder): void {
    $before = count($texts());
    $report = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY, 'check' => '1', 'AccountDomain' => '1180.DynaCloud', 'AccountUser' => '9000', 'SMSNumber' => '8457747000', 'repeat' => 'yes']));
    same(200, $report->status);
    same('text/plain; charset=UTF-8', $report->headers['Content-Type'] ?? null);
    contains($ownServers ? '(cli-server, ' : '  [ OK ] PHP ' . PHP_MAJOR_VERSION . '.', $report->body, 'report');
    contains('  [ OK ] this page was reached as ' . $scriptPath, $report->body, 'report');
    contains($ownServers ? '  [ OK ] ' . Config::nativePath($folder . '/.env') . " is valid\n         API " : ' is valid', $report->body, 'report');
    contains(' can be written by this PHP', $report->body, 'report');
    contains('  [ OK ] Prompt_900001 was read from the API', $report->body, 'report');
    contains('  [ OK ] Texts are sent from 18457747000 as user 9000', $report->body, 'report');
    contains('  [ OK ] repeat=yes: the caller is sent back to the auto attendant (forward to sip:9000@1180.DynaCloud)', $report->body, 'report');
    contains("\nResult: no problems found.\n", $report->body, 'report');
    lacks(HTTP_KEY, $report->body, 'report');
    lacks(HTTP_TOKEN, $report->body, 'report');
    same($before, count($texts()), 'the check sends nothing');
    same(403, http('GET', $entry . '?check=1')->status, 'the check needs the key');
});

test('http: log and stored data', function () use ($folder, $log): void {
    $text = $log();
    contains('] Call started {"domain":"1180.DynaCloud","aa_user":"9000","prompt":"Prompt_900001","option":"2","sms_user":"9000","from":"8455550199","caller":"8452632561"', $text, 'log');
    contains('] Text sent {"to":"18452632561","from":"18455550199"', $text, 'log');
    contains('Request rejected: the key in the URL is missing or wrong', $text, 'log');
    lacks(HTTP_KEY, $text, 'log');
    lacks(HTTP_TOKEN, $text, 'log');
    same(0, preg_match('/call=[a-f0-9]{32}/', $text), 'call ids in the log');

    $stored = array_values(array_diff(scandir($folder . '/var') ?: [], ['.', '..']));
    sort($stored);
    same(['calls', 'logs', 'sent'], $stored);
    foreach (scandir($folder . '/var/calls') ?: [] as $name) {
        ok($name === '.' || $name === '..' || preg_match('/\A[a-f0-9]{32}\.json\z/', $name) === 1, 'call state file name: ' . $name);
    }
    $sent = array_values(array_diff(scandir($folder . '/var/sent') ?: [], ['.', '..']));
    sort($sent);
    same(['2015551234.json', '2125550147.json', '3125550188.json', '6465550123.json', '7185550142.json', '8452632561.json', '9175550163.json'], $sent, 'send records, one per destination');
    same('[]', file_get_contents($folder . '/var/sent/2125550147.json'), 'the text that the API refused is not counted');
    same(3, count(json_decode((string) file_get_contents($folder . '/var/sent/3125550188.json'), true)), 'texts counted for the number that reached the limit');
});

// ---------------------------------------------------------------------------
// The settings file in another folder than the project
// ---------------------------------------------------------------------------

if ($ownServers) {
    test('http: .env is kept in another folder, and env-path.txt in the project folder says where', function () use (&$servers, $folder, $apiPort, $api): void {
        // A project folder of its own, so that the files of this checkout are left alone. Its
        // index.php does what public/index.php does, with the application code of this checkout.
        $project = $folder . '/moved/www/aatosms';
        $private = $folder . '/moved/private settings';
        mkdir($project . '/public', 0700, true);
        mkdir($private, 0700, true);
        file_put_contents(
            $project . '/public/index.php',
            '<?php require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . '; AAtoSMS\\App::run(dirname(__DIR__));'
        );
        file_put_contents($private . '/.env', settings($folder, $apiPort));
        file_put_contents($project . '/' . Config::ENV_POINTER, "# written by tests/http.php\r\n\"" . Config::nativePath($private) . "\"\r\n");

        // An empty value takes AATOSMS_ENV_FILE out of the environment of that server.
        [$servers['moved'], $port] = serve(['-t', $project . '/public'], $folder . '/moved-server.log', ['AATOSMS_ENV_FILE' => ''], '/index.php');
        $moved = 'http://127.0.0.1:' . $port . '/index.php';

        $report = http('GET', $moved . '?' . http_build_query(['key' => HTTP_KEY, 'check' => '1', 'AccountDomain' => '1180.DynaCloud', 'AccountUser' => '9000']));
        same(200, $report->status);
        contains('  [ OK ] ' . Config::nativePath($private) . Config::nativePath('/.env') . " is valid\n         found through ", $report->body, 'report');
        ok(preg_match('~^         found through .*[\\\\/]aatosms[\\\\/]env-path\.txt$~m', $report->body) === 1, "the report names env-path.txt:\n" . $report->body);
        contains('  [ OK ] Prompt_900001 was read from the API', $report->body, 'report');
        contains("\nResult: no problems found.\n", $report->body, 'report');

        // And a call starts with the settings of that file: the key, the API, the digit style.
        $before = count($api());
        $menu = http('GET', $moved . '?' . http_build_query(['key' => HTTP_KEY] + PBX + ['Digits' => '']));
        same(200, $menu->status);
        contains('the number you are calling from, 8, 4, 5, 2, 6, 3, 2, 5, 6, 1, press 1.', spoken($menu));
        same($before + 1, count($api()), 'the auto attendant was read from the API');
        same(403, http('GET', $moved . '?' . http_build_query(['key' => 'not-the-key'] + PBX))->status, 'the key of that file is needed');

        // The file is read for every request: when it names another place, the next request fails.
        file_put_contents($project . '/' . Config::ENV_POINTER, Config::nativePath($folder . '/moved/nowhere') . "\r\n");
        $broken = http('GET', $moved . '?' . http_build_query(['key' => HTTP_KEY] + PBX));
        ok(hangsUp($broken), 'without settings the call is ended');
        lacks('press 1', $broken->body, 'answer without settings');
        $bootLog = implode('', array_map('file_get_contents', glob($project . '/var/logs/*.log') ?: []));
        contains(' names ' . Config::nativePath($folder . '/moved/nowhere') . ', which does not exist or cannot be read by this PHP.', $bootLog, 'log of the project folder');
    });
}

// ---------------------------------------------------------------------------
// Addresses that requests may come from
// ---------------------------------------------------------------------------

if ($ownServers) {
    test('http: IP_ALLOW_LIST - other addresses are refused; a listed address is served, without a key when .env has none', function () use (&$servers, $folder, $apiPort, $api, $texts): void {
        // A project folder of its own, with the application code of this checkout: its .env, its log
        // and its call data are apart from those of the other tests. The requests come from 127.0.0.1.
        $project = $folder . '/listed/aatosms';
        mkdir($project . '/public', 0700, true);
        file_put_contents(
            $project . '/public/index.php',
            '<?php require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . '; AAtoSMS\\App::run(dirname(__DIR__));'
        );
        $configure = static function (string $key, string $list) use ($project, $apiPort): void {
            file_put_contents($project . '/.env', implode("\r\n", [
                '# written by tests/http.php',
                'NS_API_URL=http://127.0.0.1:' . $apiPort . '/ns-api/v2',
                'NS_API_TOKEN=' . HTTP_TOKEN,
                'WEBRESPONDER_KEY=' . $key,
                'IP_ALLOW_LIST=' . $list,
                '',
            ]));
        };
        $logged = static fn (): string => implode('', array_map('file_get_contents', glob($project . '/var/logs/*.log') ?: []));

        // The servers of the PBX, as in the README. This computer is not one of them.
        $configure(HTTP_KEY, '64.113.255.88, 64.113.255.0/24');
        [$servers['listed'], $port] = serve(['-t', $project . '/public'], $folder . '/listed-server.log', ['AATOSMS_ENV_FILE' => ''], '/index.php');
        $origin = 'http://127.0.0.1:' . $port;
        $withKey = $origin . '/index.php?' . http_build_query(['key' => HTTP_KEY, 'SMSNumber' => '8455550177'] + PBX);
        $withoutKey = $origin . '/index.php?' . http_build_query(['SMSNumber' => '8455550177'] + PBX);
        $asked = count($api());

        $refused = [
            'a call with the right key' => http('GET', $withKey),
            'the same as POST' => http('POST', $origin . '/index.php?key=' . HTTP_KEY, PBX),
            'the installation check' => http('GET', $origin . '/index.php?key=' . HTTP_KEY . '&check=1'),
            'keys of a caller' => http('GET', $origin . '/index.php?call=' . str_repeat('0', 32) . '.1&Digits=1'),
            'another method' => http('PUT', $withKey),
            // What a request says about its own origin does not count.
            'a call that claims a listed address' => http(
                'GET',
                $withKey . '&ip=64.113.255.88&REMOTE_ADDR=64.113.255.88',
                null,
                ['X-Forwarded-For: 64.113.255.88', 'X-Real-IP: 64.113.255.88', 'Client-IP: 64.113.255.88', 'Forwarded: for=64.113.255.88', 'Remote-Addr: 64.113.255.88']
            ),
        ];
        foreach ($refused as $what => $answer) {
            same(403, $answer->status, $what);
            same('Access denied.', spoken($answer), $what);
            ok(hangsUp($answer), $what . ': hung up');
            same(null, $answer->headers['Allow'] ?? null, $what . ': Allow header');
        }
        contains('WARNING [-] Request rejected: this address is not on IP_ALLOW_LIST {"ip":"127.0.0.1"}', $logged(), 'log');
        lacks('Call started', $logged(), 'log');
        lacks('Installation check requested', $logged(), 'log');
        same($asked, count($api()), 'the API was not asked');
        same(false, is_dir($project . '/var/calls'), 'no call state');

        // .env is read for every request. Now this computer is on the list, and no key is set.
        $configure('', '64.113.255.88, 64.113.255.0/24, 127.0.0.0/8');
        $sentBefore = count($texts());
        $menu = http('GET', $withoutKey);
        same(200, $menu->status);
        contains('To receive a text message at the number you are calling from', spoken($menu));
        $done = http('POST', $origin . gather($menu)['action'], PBX + ['Digits' => '1']);
        same('Your text message has been sent. Thank you for calling. Goodbye.', spoken($done));
        $sent = $texts();
        same($sentBefore + 1, count($sent));
        same(['type' => 'sms', 'message' => Bench::SCRIPT, 'destination' => '18452632561', 'from-number' => '18455550177'], end($sent));
        contains('"call_id":"http-test-orig","ip":"127.0.0.1"}', $logged(), 'the log of the call names the address');
        same(200, http('GET', $withKey)->status, 'a key that is still in the address does no harm');

        $report = http('GET', $origin . '/index.php?check=1');
        same(200, $report->status);
        contains("\n         Your address, as this server sees it: 127.0.0.1\n", $report->body, 'report');
        contains(
            "         Access .............. without a key, from these addresses only:\n"
                . "                                 64.113.255.88\n"
                . "                                 64.113.255.0/24 (64.113.255.0 to 64.113.255.255)\n"
                . "                                 127.0.0.0/8 (127.0.0.0 to 127.255.255.255)\n",
            $report->body,
            'report'
        );
        contains("\nResult: no problems found.\n", $report->body, 'report');

        // With a key in .env as well, the listed address needs it too.
        $configure(HTTP_KEY, '127.0.0.1');
        $noKey = http('GET', $withoutKey);
        same(403, $noKey->status);
        same('Access denied.', spoken($noKey));
        contains('WARNING [-] Request rejected: the key in the URL is missing or wrong {"ip":"127.0.0.1"}', $logged(), 'log');
        same(200, http('GET', $withKey)->status, 'with the key');

        // A list that cannot be read lets nobody in: the application does not start.
        $configure(HTTP_KEY, '127.0.0.1, 127.0.0.300');
        $broken = http('GET', $withKey);
        ok(hangsUp($broken), 'the call is ended');
        lacks('press 1', $broken->body, 'answer with an unreadable list');
        contains('ERROR   [-] Request failed: Invalid configuration: IP_ALLOW_LIST: "127.0.0.300" is not an IP address or a block of addresses.', $logged(), 'log');
    });
}

// ---------------------------------------------------------------------------
// HTTPS: the certificate of the API server is verified
// ---------------------------------------------------------------------------

if ($ownServers && extension_loaded('openssl')) {
    [$tlsPort] = freePorts(1);
    $seen = static fn (): array => is_file($folder . '/tls.log') ? (file($folder . '/tls.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    $read = static fn (string $host, string $caFile): array => (new NetSapiens('https://' . $host . ':' . $tlsPort . '/ns-api/v2', HTTP_TOKEN, 5, $caFile))
        ->autoAttendant('1180.DynaCloud', '9000', 'Prompt_900001');

    test('https: test server with a self-signed certificate', function () use (&$servers, $folder, $tlsPort): void {
        makeCertificate($folder);
        $servers['tls'] = startServer(
            [__DIR__ . '/fixtures/tls-server.php', (string) $tlsPort, $folder . '/server.pem', $folder . '/tls.log'],
            $folder . '/tls-server.log',
            []
        );
        ok(listens($tlsPort), 'the HTTPS test server listens');
    });

    test('https: a certificate that cannot be verified is refused, and nothing is sent', function () use ($read, $seen): void {
        $error = throws(ApiException::class, static fn () => $read('localhost', ''), 'failed: cURL error');
        same(0, $error->status);
        ok($error->hint() !== '', 'the log gets a hint: ' . $error->getMessage());
        same([], $seen(), 'requests that reached the server');
    });

    test('https: NS_API_CA_FILE makes PHP trust exactly that certificate', function () use ($read, $seen, $folder): void {
        same('9000', $read('localhost', $folder . '/ca.pem')['user']);
        same(['GET /ns-api/v2/domains/1180.DynaCloud/users/9000/autoattendants/Prompt_900001 HTTP/1.1 | Bearer ' . HTTP_TOKEN], $seen());
    });

    test('https: a certificate issued for another host name is refused, and nothing is sent', function () use ($read, $seen, $folder): void {
        $error = throws(ApiException::class, static fn () => $read('127.0.0.1', $folder . '/ca.pem'), 'failed: cURL error');
        ok($error->hint() !== '', 'the log gets a hint: ' . $error->getMessage());
        same(1, count($seen()), 'requests that reached the server (the one of the test before)');
    });
} elseif ($ownServers) {
    echo "Skipped: the HTTPS certificate tests need the openssl extension.\n";
}

if ($ownServers) {
    test('http: the API cannot be reached - apology, and the cause is in the log', function () use (&$servers, $entry, $log): void {
        stopServer($servers['api']);
        unset($servers['api']);

        $answer = http('GET', $entry . '?' . http_build_query(['key' => HTTP_KEY] + PBX));
        same(200, $answer->status);
        contains('We are sorry, we are unable to send the text message', spoken($answer));
        ok(hangsUp($answer), 'the call is hung up');
        contains('Call cannot start: ns-api GET /domains/1180.DynaCloud/users/9000/autoattendants/Prompt_900001 failed: cURL error', $log());
        contains('"hint":"The API server could not be reached in time: check the firewall and NS_API_URL."', $log());
    });
}

// ---------------------------------------------------------------------------

$serverOutput = '';
if (Results::$failures !== [] && $ownServers) {
    // What the servers printed (PHP messages, requests) helps to explain a failure.
    foreach (glob($folder . '/*-server.log') ?: [] as $file) {
        $lines = preg_grep('/ (Accepted|Closing|Closed without sending a request; .*)\s*\z/', file($file, FILE_IGNORE_NEW_LINES) ?: [], PREG_GREP_INVERT);
        $serverOutput .= basename($file) . ":\n  " . implode("\n  ", $lines ?: []) . "\n\n";
    }
}
$stop();

exit(finish('over HTTP, ', $started, $serverOutput));
