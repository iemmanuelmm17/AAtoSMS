<?php

declare(strict_types=1);

namespace AAtoSMS;

/**
 * Parameters of one Web Responder request.
 *
 * The NetSapiens core sends its values (NmsAni, NmsDnis, AccountUser, Digits ...)
 * by GET, or in a form-encoded POST body when the dial translation contains
 * <HttpMethod=POST>. Values configured on the To-Web URL (?key=...&SMSNumber=...)
 * always arrive in the query string. Lookups therefore check the POST body first,
 * then the URL, and take the first value that is not blank. Parameter names are
 * matched case-insensitively.
 *
 * $path is the address of the script on this server (/aatosms/index.php); it is
 * '' when the request does not come from a web server (command line, tests).
 */
final class Request
{
    private const MAX_VALUE_LENGTH = 512;

    /** @var array<string, string> */
    private array $post;

    /** @var array<string, string> */
    private array $query;

    /**
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $query
     */
    public function __construct(
        array $post = [],
        array $query = [],
        public readonly string $method = 'POST',
        public readonly string $ip = '',
        public readonly string $path = '',
    ) {
        $this->post = self::normalize($post);
        $this->query = self::normalize($query);
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $post = $_POST;
        if ($post === [] && $method === 'POST') {
            // PHP only fills $_POST for form content types; accept a form body sent without one.
            $raw = trim((string) @file_get_contents('php://input', false, null, 0, 65536));
            if ($raw !== '' && $raw[0] !== '<' && $raw[0] !== '{' && $raw[0] !== '[') {
                parse_str($raw, $post);
            }
        }

        return new self(
            $post,
            $_GET,
            $method,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        );
    }

    /** First non-blank value from the POST body, then the URL; '' when absent. */
    public function get(string $name): string
    {
        $key = strtolower($name);
        $value = $this->post[$key] ?? '';

        return $value !== '' ? $value : ($this->query[$key] ?? '');
    }

    /**
     * All received parameters for debug logging, with capabilities masked.
     *
     * @return array<string, string>
     */
    public function snapshot(): array
    {
        $all = $this->post + $this->query;
        foreach (['key', 'call'] as $secret) {
            if (isset($all[$secret]) && $all[$secret] !== '') {
                $all[$secret] = '[redacted]';
            }
        }
        ksort($all);

        return $all;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, string>
     */
    private static function normalize(array $values): array
    {
        $clean = [];
        foreach ($values as $name => $value) {
            if (!is_scalar($value)) {
                continue; // arrays such as ?Digits[]=1 are never valid here
            }
            $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value) ?? '');
            if (strlen($value) > self::MAX_VALUE_LENGTH) {
                continue;
            }
            $clean[strtolower((string) $name)] = $value;
        }

        return $clean;
    }
}
