<?php

declare(strict_types=1);

/**
 * Test helpers. No test framework, no network: the PBX API is replaced by FakeApi
 * and every test works in its own temporary folder.
 */

namespace AAtoSMS\Tests;

use AAtoSMS\App;
use AAtoSMS\Config;
use AAtoSMS\Request;
use AAtoSMS\Response;

final class Failure extends \Exception
{
}

final class Results
{
    public static int $passed = 0;

    /** @var list<string> */
    public static array $failures = [];
}

function test(string $name, callable $body): void
{
    try {
        $body();
        Results::$passed++;
    } catch (\Throwable $error) {
        $where = basename($error->getFile()) . ':' . $error->getLine();
        foreach ($error->getTrace() as $frame) {
            if (isset($frame['file']) && !str_ends_with($frame['file'], 'support.php') && str_contains(str_replace('\\', '/', $frame['file']), '/tests/')) {
                $where = basename($frame['file']) . ':' . $frame['line'];
                break;
            }
        }
        Results::$failures[] = sprintf("FAIL  %s\n      %s (%s)", $name, str_replace("\n", "\n      ", $error->getMessage()), $where);
    }
}

/**
 * Prints the failed tests and the one-line result. On GitHub Actions the result is
 * also shown on the summary page of the run.
 *
 * @param string $what    which tests these were, as the start of the text in brackets
 * @param string $details extra output that helps to explain failures
 * @return int exit code: 0 when everything passed
 */
function finish(string $what, float $started, string $details = ''): int
{
    foreach (Results::$failures as $failure) {
        echo $failure, "\n\n";
    }
    echo $details;

    $passed = Results::$failures === [];
    $result = sprintf(
        '%s: %d passed, %d failed (%sPHP %s, %s, %.1f s)',
        $passed ? 'OK' : 'FAILED',
        Results::$passed,
        count(Results::$failures),
        $what,
        PHP_VERSION,
        PHP_OS_FAMILY,
        microtime(true) - $started
    );
    echo $result, "\n";

    if (getenv('GITHUB_ACTIONS') === 'true') {
        $escape = static fn (string $text): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $text);
        foreach (array_slice(Results::$failures, 0, 10) as $failure) {
            echo '::error::', $escape($failure), "\n";
        }
        echo '::', $passed ? 'notice' : 'error', '::', $escape($result), "\n";
    }

    return $passed ? 0 : 1;
}

function same(mixed $expected, mixed $actual, string $what = 'value'): void
{
    if ($expected !== $actual) {
        throw new Failure(sprintf("%s differs\nexpected: %s\nactual:   %s", $what, var_export($expected, true), var_export($actual, true)));
    }
}

function ok(mixed $condition, string $what): void
{
    if ($condition !== true) {
        throw new Failure('expected: ' . $what);
    }
}

function contains(string $needle, string $haystack, string $what = 'text'): void
{
    if (!str_contains($haystack, $needle)) {
        throw new Failure(sprintf("%s does not contain %s\nactual: %s", $what, var_export($needle, true), $haystack));
    }
}

function lacks(string $needle, string $haystack, string $what = 'text'): void
{
    if (str_contains($haystack, $needle)) {
        throw new Failure(sprintf('%s must not contain %s', $what, var_export($needle, true)));
    }
}

function throws(string $class, callable $body, string $messagePart = ''): \Throwable
{
    try {
        $body();
    } catch (\Throwable $error) {
        if (!$error instanceof $class) {
            throw new Failure(sprintf('expected %s, got %s: %s', $class, $error::class, $error->getMessage()));
        }
        if ($messagePart !== '' && !str_contains($error->getMessage(), $messagePart)) {
            throw new Failure(sprintf("exception message does not contain %s\nactual: %s", var_export($messagePart, true), $error->getMessage()));
        }

        return $error;
    }

    throw new Failure(sprintf('expected %s, but nothing was thrown', $class));
}

/** Parses a response body; fails when it is not a well-formed <Response> document. */
function xml(Response|string $response): \DOMDocument
{
    $body = $response instanceof Response ? $response->body : $response;
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($body, LIBXML_NONET);
    $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || $errors !== []) {
        throw new Failure("response is not well-formed XML: " . implode('; ', $errors) . "\n" . $body);
    }
    if ($document->documentElement?->nodeName !== 'Response') {
        throw new Failure("root element is not <Response>\n" . $body);
    }
    if (!str_starts_with($body, '<?xml version="1.0" encoding="UTF-8"?>')) {
        throw new Failure("XML declaration missing\n" . $body);
    }

    return $document;
}

/** Everything the caller hears, in order. */
function spoken(Response|string $response): string
{
    $parts = [];
    foreach (xml($response)->getElementsByTagName('Say') as $say) {
        $parts[] = $say->textContent;
    }

    return implode(' ', $parts);
}

/**
 * Attributes of the <Gather>, or null when the answer does not wait for keys.
 *
 * @return array<string, string>|null
 */
function gather(Response|string $response): ?array
{
    $element = xml($response)->getElementsByTagName('Gather')->item(0);
    if ($element === null) {
        return null;
    }
    $attributes = [];
    foreach ($element->attributes as $attribute) {
        $attributes[$attribute->nodeName] = $attribute->nodeValue;
    }

    return $attributes;
}

/** The last verb of the answer: what the PBX does once everything before it is done. */
function lastVerb(\DOMDocument $document): ?\DOMElement
{
    $last = null;
    foreach ($document->documentElement->childNodes as $node) {
        if ($node instanceof \DOMElement) {
            $last = $node;
        }
    }

    return $last;
}

/** True when the answer ends the call: <Hangup/> is the last verb, nothing waits for keys, nothing is forwarded. */
function hangsUp(Response|string $response): bool
{
    $document = xml($response);

    return lastVerb($document)?->nodeName === 'Hangup'
        && $document->getElementsByTagName('Gather')->length === 0
        && $document->getElementsByTagName('Forward')->length === 0;
}

/**
 * The destination the answer forwards the call to. Null unless a plain <Forward> is the
 * last verb and the only one of its kind, and nothing waits for keys or hangs up.
 */
function forwardsTo(Response|string $response): ?string
{
    $document = xml($response);
    $last = lastVerb($document);
    if ($last?->nodeName !== 'Forward'
        || $last->attributes->length !== 0
        || $document->getElementsByTagName('Forward')->length !== 1
        || $document->getElementsByTagName('Gather')->length !== 0
        || $document->getElementsByTagName('Hangup')->length !== 0
    ) {
        return null;
    }

    return $last->textContent;
}

function fixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
}

function temporaryFolder(): string
{
    $folder = rtrim(sys_get_temp_dir(), '/\\') . '/aatosms-test-' . bin2hex(random_bytes(6));
    mkdir($folder, 0700, true);

    return $folder;
}

function removeFolder(string $folder): void
{
    if (!is_dir($folder)) {
        return;
    }
    foreach (scandir($folder) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $folder . '/' . $name;
        is_dir($path) ? removeFolder($path) : @unlink($path);
    }
    @rmdir($folder);
}

/** Stands in for the NetSapiens API: records every request and answers from a script. */
final class FakeApi
{
    /** @var list<array{method:string, url:string, headers:list<string>, body:?string, options:array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{status:int, body:string, error:string}> answers for the next GETs; then the fixture */
    public array $getAnswers = [];

    /** @var list<array{status:int, body:string, error:string}> answers for the next POSTs; then "accepted" */
    public array $postAnswers = [];

    /** Called instead of answering a POST, e.g. to simulate a crash. */
    public ?\Closure $onPost = null;

    public string $autoAttendant;

    public function __construct()
    {
        $this->autoAttendant = fixture('autoattendant.json');
    }

    /**
     * @param list<string>         $headers
     * @param array<string, mixed> $options
     * @return array{status:int, body:string, error:string}
     */
    public function __invoke(string $method, string $url, array $headers, ?string $body, array $options): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
        if ($method === 'GET') {
            return array_shift($this->getAnswers) ?? self::answer(200, $this->autoAttendant);
        }
        if ($this->onPost !== null) {
            return ($this->onPost)($url, $body);
        }

        return array_shift($this->postAnswers) ?? self::answer(200, '{"code":202,"message":"Accepted"}');
    }

    /** @return array{status:int, body:string, error:string} */
    public static function answer(int $status, string $body = '', string $error = ''): array
    {
        return ['status' => $status, 'body' => $body, 'error' => $error];
    }

    /** @return list<array{method:string, url:string, headers:list<string>, body:?string, options:array<string, mixed>}> */
    public function requestsOf(string $method): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => $request['method'] === $method));
    }

    /**
     * The texts that were sent: decoded JSON bodies of all POSTs.
     *
     * @return list<array<string, mixed>>
     */
    public function texts(): array
    {
        return array_map(static fn (array $request): array => json_decode((string) $request['body'], true, 16, JSON_THROW_ON_ERROR), $this->requestsOf('POST'));
    }
}

/** One installation of the application in a temporary folder, plus a phone to call it with. */
final class Bench
{
    public const KEY = 'test-key-0123456789abcdefghij';
    public const TOKEN = 'nss_TESTtoken-1234567890';
    public const SCRIPT = 'Thank you for calling Example Dental. Our office is at 100 Main Street. Reply to this message to book an appointment.';

    public string $folder;
    public Config $config;
    public FakeApi $api;
    public App $app;
    public ?Response $last = null;

    /** The address that the requests of the phone come from. */
    public string $ip = '203.0.113.9';

    private string $path;

    /**
     * @param array<string, string> $env  settings on top of the minimum
     * @param string                $self path at which the web server runs the script; becomes
     *                                    the address that callbacks are expected to start with
     */
    public function __construct(array $env = [], public string $self = '/aatosms/index.php')
    {
        $this->folder = temporaryFolder();
        $this->config = Config::fromArray($env + [
            'NS_API_URL' => 'https://pbx.example.test/ns-api/v2',
            'NS_API_TOKEN' => self::TOKEN,
            'WEBRESPONDER_KEY' => self::KEY,
            'LOG_LEVEL' => 'debug',
        ], $this->folder);
        $this->api = new FakeApi();
        $this->app = App::fromConfig($this->config, App::loggerFor($this->config), $this->api);
        $this->path = $self;
        $this->self = $this->config->scriptUrl($self);
    }

    public function __destruct()
    {
        removeFolder($this->folder);
    }

    /**
     * The first request of a call, with the values the PBX sends. Pass a value
     * to change it, or null to leave a parameter out.
     *
     * @param array<string, ?string> $changes
     */
    public function call(array $changes = [], string $method = 'GET'): Response
    {
        $parameters = array_filter($changes + [
            'key' => self::KEY,
            'NmsAni' => '8452632561',
            'NmsDnis' => '8457747000',
            'AccountUser' => '9000',
            'AccountDomain' => '1180.DynaCloud',
            'OrigCallID' => '20261111-abc123',
        ], static fn (?string $value): bool => $value !== null);

        return $this->last = $this->send($parameters, [], $method);
    }

    /** The caller presses keys: requests the action URL of the last <Gather> with Digits. */
    public function press(string $digits, string $method = 'GET'): Response
    {
        return $this->last = $this->send(
            ['Digits' => $digits, 'NmsAni' => '8452632561', 'NmsDnis' => '8457747000', 'AccountUser' => '9000', 'AccountDomain' => '1180.DynaCloud'],
            $this->callbackQuery(),
            $method
        );
    }

    /**
     * Query string of the callback URL in the last answer.
     *
     * @return array<string, string>
     */
    public function callbackQuery(): array
    {
        $action = $this->last === null ? null : (gather($this->last)['action'] ?? null);
        if ($action === null) {
            throw new Failure('the last answer has no <Gather action>, so no key can be pressed');
        }
        if (!str_starts_with($action, $this->self . '?')) {
            throw new Failure(sprintf('callback URL %s does not start with %s?', $action, $this->self));
        }
        parse_str((string) parse_url($action, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @param array<string, string> $parameters sent by the PBX (query string for GET, body for POST)
     * @param array<string, string> $query      parameters that are part of the URL itself
     */
    public function send(array $parameters, array $query = [], string $method = 'GET'): Response
    {
        $request = $method === 'POST'
            ? new Request($parameters, $query, 'POST', $this->ip, $this->path)
            : new Request([], $parameters + $query, $method, $this->ip, $this->path);

        return $this->app->handle($request);
    }

    /** Everything written to the log so far. */
    public function log(): string
    {
        $text = '';
        foreach (glob($this->config->storagePath . '/logs/*.log') ?: [] as $file) {
            $text .= file_get_contents($file);
        }

        return $text;
    }

    /**
     * Saved state of every call.
     *
     * @return list<array<string, mixed>>
     */
    public function calls(): array
    {
        $calls = [];
        foreach (glob($this->config->storagePath . '/calls/*.json') ?: [] as $file) {
            $calls[] = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        }

        return $calls;
    }
}
