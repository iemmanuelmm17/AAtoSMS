<?php

declare(strict_types=1);

namespace AAtoSMS;

/** A failed ns-api call. $status is the HTTP status, or 0 when no HTTP response arrived. */
final class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $responseBody = '',
    ) {
        parent::__construct($message);
    }

    /** A plain-language pointer to the usual cause, for the log. */
    public function hint(): string
    {
        $message = $this->getMessage();
        $has = static fn (string ...$parts): bool => array_filter($parts, static fn (string $part): bool => str_contains($message, $part)) !== [];

        return match (true) {
            $this->status === 401 => 'The API rejected NS_API_TOKEN: it is wrong, expired or revoked.',
            $this->status === 403 => 'NS_API_TOKEN is valid but is not allowed to do this for that domain/user.',
            $this->status === 404 => 'Not found: check the domain, the user/extension and the prompt name in the request path.',
            $this->status === 429 => 'The API is rate limiting requests.',
            $this->status >= 500 => 'The NetSapiens server reported an internal error.',
            $has('cURL error 51', 'subject name', 'WRONG_PRINCIPAL') => 'The HTTPS certificate of the API server is not valid for the host name in NS_API_URL: use the name the certificate was issued for.',
            $has('cURL error 60') && $has('expired') => 'The HTTPS certificate of the API server has expired; it has to be renewed on that server.',
            $has('cURL error 60', 'cURL error 77') => 'PHP cannot verify the HTTPS certificate: set curl.cainfo in php.ini or NS_API_CA_FILE in .env to a CA bundle (cacert.pem).',
            $has('cURL error 6:') => 'The host name in NS_API_URL could not be resolved (DNS).',
            $has('cURL error 7:', 'cURL error 28') => 'The API server could not be reached in time: check the firewall and NS_API_URL.',
            default => '',
        };
    }
}

/**
 * Minimal NetSapiens ns-api v2 client: read an auto attendant and send an SMS.
 *
 *   GET  {base}/domains/{domain}/users/{user}/autoattendants/{prompt}
 *   POST {base}/domains/{domain}/users/{user}/messages
 */
final class NetSapiens
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /** @var callable(string, string, list<string>, ?string, array{timeout:int, connect_timeout:int, ca_file:string}): array{status:int, body:string, error:string} */
    private $transport;

    /**
     * @param callable|null $transport replaces cURL in tests; see curlTransport() for the contract
     */
    public function __construct(
        private string $baseUrl,
        private string $token,
        private int $timeout = 10,
        private string $caFile = '',
        ?callable $transport = null,
        private ?Logger $logger = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? self::curlTransport(...);
    }

    /**
     * Reads one auto attendant.
     *
     * @return array<string, mixed>
     */
    public function autoAttendant(string $domain, string $user, string $prompt): array
    {
        $path = sprintf('/domains/%s/users/%s/autoattendants/%s', rawurlencode($domain), rawurlencode($user), rawurlencode($prompt));
        $response = $this->request('GET', $path, null, true);

        $data = json_decode($response['body'], true);
        if (is_array($data) && array_is_list($data) && count($data) === 1 && is_array($data[0])) {
            $data = $data[0];
        }
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            throw new ApiException(sprintf('ns-api GET %s did not return an auto attendant (JSON object expected).', $path), $response['status'], self::excerpt($response['body']));
        }

        return $data;
    }

    /**
     * Sends one SMS. Any 2xx answer counts as accepted.
     *
     * @return array{status:int, body:string} the answer of the API, for the log
     */
    public function sendSms(string $domain, string $user, string $destination, string $fromNumber, string $message): array
    {
        $path = sprintf('/domains/%s/users/%s/messages', rawurlencode($domain), rawurlencode($user));
        $body = json_encode([
            'type' => 'sms',
            'message' => $message,
            'destination' => $destination,
            'from-number' => $fromNumber,
        ], self::JSON_FLAGS);

        // Never repeated automatically: a retry after a timeout could deliver the text twice.
        $response = $this->request('POST', $path, $body, false);

        return ['status' => $response['status'], 'body' => self::excerpt($response['body'])];
    }

    /**
     * @return array{status:int, body:string}
     */
    private function request(string $method, string $path, ?string $body, bool $retryOnce): array
    {
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $options = ['timeout' => $this->timeout, 'connect_timeout' => min(5, $this->timeout), 'ca_file' => $this->caFile];

        for ($attempt = 1; ; $attempt++) {
            $started = microtime(true);
            $result = ($this->transport)($method, $this->baseUrl . $path, $headers, $body, $options);
            $seconds = microtime(true) - $started;

            $status = (int) ($result['status'] ?? 0);
            $error = (string) ($result['error'] ?? '');
            $responseBody = (string) ($result['body'] ?? '');
            $this->logger?->debug(sprintf('ns-api %s %s', $method, $path), [
                'http_status' => $status,
                'ms' => (int) round($seconds * 1000),
                'error' => $error,
            ]);

            // One quick second try for reads only, when the failure looks temporary.
            $temporary = $error !== '' || $status >= 500 || $status === 429;
            if (!$temporary || !$retryOnce || $attempt >= 2 || $seconds > 3.0) {
                break;
            }
            $this->logger?->warning(sprintf('ns-api %s %s failed, trying once more', $method, $path), ['http_status' => $status, 'error' => $error]);
            usleep(250000);
        }

        if ($error !== '') {
            throw new ApiException(sprintf('ns-api %s %s failed: %s', $method, $path, $error));
        }
        if ($status < 200 || $status > 299) {
            throw new ApiException(sprintf('ns-api %s %s returned HTTP %d', $method, $path, $status), $status, self::excerpt($responseBody));
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    /** One line, at most 300 characters, safe to write to the log. */
    private static function excerpt(string $body): string
    {
        return Text::shorten($body, 303);
    }

    /**
     * Default transport. Certificates are always verified and redirects are never followed.
     *
     * @param list<string>                                              $headers
     * @param array{timeout:int, connect_timeout:int, ca_file:string}   $options
     * @return array{status:int, body:string, error:string} error is '' when an HTTP response was received
     */
    public static function curlTransport(string $method, string $url, array $headers, ?string $body, array $options): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'The PHP cURL extension is not enabled (add extension=curl to php.ini).'];
        }

        $handle = curl_init();
        $settings = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'],
            CURLOPT_TIMEOUT => $options['timeout'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'AAtoSMS/' . VERSION,
        ];
        if ($method === 'POST') {
            $settings[CURLOPT_POST] = true;
            $settings[CURLOPT_POSTFIELDS] = $body ?? '';
        }
        if ($options['ca_file'] !== '') {
            $settings[CURLOPT_CAINFO] = $options['ca_file'];
        }
        curl_setopt_array($handle, $settings);

        // Windows PHP ships without a CA bundle. When none is configured anywhere,
        // verify against the Windows certificate store instead of failing (error 60).
        if ($options['ca_file'] === '' && PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')
            && (string) ini_get('curl.cainfo') === '' && (string) ini_get('openssl.cafile') === '') {
            @curl_setopt($handle, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }

        $response = curl_exec($handle);
        if (!is_string($response)) {
            return ['status' => 0, 'body' => '', 'error' => sprintf('cURL error %d: %s', curl_errno($handle), curl_error($handle))];
        }

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $response, 'error' => ''];
    }
}
