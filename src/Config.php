<?php

declare(strict_types=1);

namespace AAtoSMS;

final class ConfigException extends \RuntimeException
{
}

/**
 * Immutable application settings, read from the .env file.
 *
 * The .env file is in the project folder, unless the text file env-path.txt in the
 * project folder names another place - see locate(). That keeps the file with the
 * API token out of a folder that a web server can reach.
 *
 * The .env file is the only source of settings (real environment variables are
 * deliberately ignored so an unrelated system-wide variable can never override
 * what is written in the file). The one exception is AATOSMS_ENV_FILE, which names
 * the settings file for one process; the tests use it.
 */
final class Config
{
    public const DIGIT_STYLES = ['words', 'commas', 'hyphens', 'spaces'];
    public const NUMBER_FORMATS = ['11digit', '10digit', 'e164'];
    public const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];
    public const MIN_KEY_LENGTH = 24;

    /** Optional text file in the project folder that says where the .env file is. */
    public const ENV_POINTER = 'env-path.txt';

    /** Spoken prompts. Override any of them with PROMPT_<NAME> in .env. */
    public const DEFAULT_PROMPTS = [
        'menu' => 'To receive a text message at the number you are calling from, {number}, press 1. To enter a different mobile number, press 2.',
        'no_caller_id' => 'We can send you a text message.',
        'enter_number' => 'Please enter the ten digit mobile number, starting with the area code.',
        'confirm' => 'You entered {number}. To send the text message to this number, press 1. To enter a different number, press 2.',
        'invalid_choice' => 'Sorry, that is not a valid selection.',
        'invalid_number' => 'Sorry, that is not a valid ten digit number.',
        'no_input' => 'Sorry, we did not receive a response.',
        'sent' => 'Your text message has been sent. Thank you for calling. Goodbye.',
        'goodbye' => 'We did not receive a valid response. Goodbye.',
        'limit' => 'A text message was already sent to this number recently. Please check your messages, or try again later. Goodbye.',
        'error' => 'We are sorry, we are unable to send the text message at this time. Please try again later. Goodbye.',
    ];

    private const KNOWN_KEYS = [
        'NS_API_URL', 'NS_API_TOKEN', 'NS_API_TIMEOUT', 'NS_API_CA_FILE', 'NS_DEFAULT_DOMAIN', 'WEBRESPONDER_KEY', 'APP_URL',
        'SMS_SCRIPT_OPTION', 'SMS_USER', 'SMS_NUMBER_FORMAT', 'SMS_LIMIT_PER_DESTINATION', 'SMS_LIMIT_WINDOW_MINUTES',
        'CALLER_ID_DIGIT_STYLE', 'TTS_VOICE', 'TTS_LANGUAGE', 'INPUT_TIMEOUT_SECONDS', 'INTERDIGIT_TIMEOUT_SECONDS',
        'MAX_ATTEMPTS', 'LOG_LEVEL', 'LOG_RETENTION_DAYS', 'LOG_TIMEZONE', 'STORAGE_PATH',
    ];

    /**
     * @param string                $settingsFile the .env file the settings were read from; '' when they come from an array
     * @param string                $pointerFile  the env-path.txt that named that file; '' when none did
     * @param array<string, string> $prompts
     * @param list<string>          $unknownKeys  keys found in the file that this application does not use
     */
    private function __construct(
        public readonly string $projectRoot,
        public readonly string $settingsFile,
        public readonly string $pointerFile,
        public readonly string $apiUrl,
        public readonly string $apiToken,
        public readonly int $apiTimeout,
        public readonly string $apiCaFile,
        public readonly string $defaultDomain,
        public readonly string $responderKey,
        public readonly string $appUrl,
        public readonly string $smsOption,
        public readonly string $smsUser,
        public readonly string $numberFormat,
        public readonly int $limitPerDestination,
        public readonly int $limitWindowMinutes,
        public readonly string $digitStyle,
        public readonly string $ttsVoice,
        public readonly string $ttsLanguage,
        public readonly int $inputTimeout,
        public readonly int $interdigitTimeout,
        public readonly int $maxAttempts,
        public readonly string $logLevel,
        public readonly int $logRetentionDays,
        public readonly string $logTimezone,
        public readonly string $storagePath,
        public readonly array $prompts,
        public readonly array $unknownKeys,
    ) {
    }

    /**
     * Where the settings file is, and what says so.
     *
     * It is ".env" in the project folder. To keep it somewhere else, the text file
     * env-path.txt in the project folder names the place on a line of its own: the
     * folder that holds .env, or the file itself. A relative path starts at the
     * project folder. Lines that start with # are comments, and a file without a
     * path changes nothing.
     *
     * @return array{file:string, pointer:string} file: the settings file, which need not exist when it is the
     *                                             one of the project folder. pointer: the env-path.txt that
     *                                             names the file, '' when none does
     * @throws ConfigException when env-path.txt cannot be used, or names a place without a settings file
     */
    public static function locate(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/\\');
        $override = getenv('AATOSMS_ENV_FILE');
        if (is_string($override) && trim($override) !== '') {
            return ['file' => self::resolvePath(trim($override), $root), 'pointer' => ''];
        }

        $pointer = $root . '/' . self::ENV_POINTER;
        $named = is_file($pointer) ? self::readPointer($pointer) : '';
        if ($named === '') {
            return ['file' => $root . '/.env', 'pointer' => ''];
        }

        $path = self::resolvePath($named, $root);
        if (is_dir($path)) {
            if (!is_file($path . '/.env')) {
                throw new ConfigException(sprintf(
                    '%s names the folder %s, but there is no .env file in it, or this PHP cannot read it.',
                    self::nativePath($pointer),
                    self::nativePath($path)
                ));
            }

            return ['file' => $path . '/.env', 'pointer' => $pointer];
        }
        if (!is_file($path)) {
            throw new ConfigException(sprintf(
                '%s names %s, which does not exist or cannot be read by this PHP. Write the folder that holds .env, or the file itself; the account that runs PHP needs read permission there.',
                self::nativePath($pointer),
                self::nativePath($path !== '' ? $path : $named)
            ));
        }

        return ['file' => $path, 'pointer' => $pointer];
    }

    public static function load(string $projectRoot): self
    {
        $where = self::locate($projectRoot);
        $file = $where['file'];
        if (!is_file($file)) {
            throw new ConfigException(sprintf(
                'Configuration file not found: %s. Copy .env.example to .env and fill it in. If .env is kept in another folder, name that folder in %s (see env-path.example.txt).',
                self::nativePath($file),
                self::ENV_POINTER
            ));
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new ConfigException(sprintf('Configuration file is not readable by PHP: %s', self::nativePath($file)));
        }

        return self::fromArray(self::parseEnv($contents), $projectRoot, $file, $where['pointer']);
    }

    /**
     * Parses .env text into NAME => value pairs.
     *
     * Rules: one NAME=value per line; blank lines and lines starting with # are
     * ignored; a value may be wrapped in matching single or double quotes; there
     * are no inline comments, so everything after the first "=" is the value.
     *
     * @return array<string, string>
     */
    public static function parseEnv(string $contents): array
    {
        $values = [];
        foreach (self::lines($contents, 'The .env file') as $line) {
            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }
            $name = trim(substr($line, 0, $separator));
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1) {
                continue;
            }
            $values[$name] = self::unquote(trim(substr($line, $separator + 1)));
        }

        return $values;
    }

    /**
     * The path written in env-path.txt; '' when the file holds only comments.
     */
    private static function readPointer(string $pointer): string
    {
        $name = self::nativePath($pointer);
        $contents = @file_get_contents($pointer, false, null, 0, 65536);
        if ($contents === false) {
            throw new ConfigException(sprintf('%s exists, but this PHP cannot read it.', $name));
        }
        $paths = array_map(self::unquote(...), self::lines($contents, $name));
        if (count($paths) > 1) {
            throw new ConfigException(sprintf('%s has to contain one path, on a line of its own. It contains %d lines that are not comments.', $name, count($paths)));
        }

        return trim($paths[0] ?? '');
    }

    /**
     * The lines of a settings file that say something: trimmed, without blank
     * lines and without comments (lines that start with #).
     *
     * @param string $what name of the file, for the message about a wrong encoding
     * @return list<string>
     */
    private static function lines(string $contents, string $what): array
    {
        if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF") || str_contains(substr($contents, 0, 64), "\0")) {
            throw new ConfigException($what . ' is saved as UTF-16. Re-save it with UTF-8 encoding (Notepad: Save As > Encoding > UTF-8).');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3); // UTF-8 byte order mark written by some Windows editors
        }

        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Removes one pair of quotes around a value ("D:\my files" - Explorer's "Copy as path" adds them). */
    private static function unquote(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$length - 1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Builds and validates the configuration. Every problem is reported at once.
     *
     * @param array<string, string> $values
     * @param string                $settingsFile the file the values were read from, if any
     * @param string                $pointerFile  the env-path.txt that named that file, if any
     */
    public static function fromArray(array $values, string $projectRoot, string $settingsFile = '', string $pointerFile = ''): self
    {
        $errors = [];
        $projectRoot = rtrim($projectRoot, '/\\');
        $get = static fn (string $key, string $default = ''): string => trim((string) ($values[$key] ?? '')) !== '' ? trim((string) $values[$key]) : $default;

        $integer = static function (string $key, int $default, int $min, int $max) use ($get, &$errors): int {
            $raw = $get($key, (string) $default);
            if (preg_match('/\A[0-9]{1,9}\z/', $raw) !== 1 || (int) $raw < $min || (int) $raw > $max) {
                $errors[] = sprintf('%s must be a whole number between %d and %d (found "%s").', $key, $min, $max, $raw);

                return $default;
            }

            return (int) $raw;
        };

        $choice = static function (string $key, string $default, array $allowed) use ($get, &$errors): string {
            $raw = strtolower($get($key, $default));
            if (!in_array($raw, $allowed, true)) {
                $errors[] = sprintf('%s must be one of: %s (found "%s").', $key, implode(', ', $allowed), $raw);

                return $default;
            }

            return $raw;
        };

        $apiUrl = rtrim($get('NS_API_URL'), '/');
        if ($apiUrl === '') {
            $errors[] = 'NS_API_URL is required, e.g. https://your-server/ns-api/v2';
        } elseif (!self::isHttpUrl($apiUrl)) {
            $errors[] = 'NS_API_URL must be a full URL starting with https://';
        } elseif (!self::isSecureOrLocal($apiUrl)) {
            $errors[] = 'NS_API_URL must use https:// (plain http is only accepted for localhost testing) so the API token is never sent unencrypted.';
        }

        $apiToken = $get('NS_API_TOKEN');
        if ($apiToken === '') {
            $errors[] = 'NS_API_TOKEN is required (the NetSapiens API key / bearer token).';
        } elseif (preg_match('/\A[\x21-\x7E]+\z/', $apiToken) !== 1) {
            $errors[] = 'NS_API_TOKEN contains spaces or non-printable characters.';
        }

        $responderKey = $get('WEBRESPONDER_KEY');
        if (strlen($responderKey) < self::MIN_KEY_LENGTH) {
            $errors[] = sprintf('WEBRESPONDER_KEY is required and must be at least %d characters long.', self::MIN_KEY_LENGTH);
        } elseif (preg_match('/\A[A-Za-z0-9._~-]+\z/', $responderKey) !== 1) {
            $errors[] = 'WEBRESPONDER_KEY may only contain letters, digits and . _ ~ - so it can be used in a URL as is.';
        } elseif ($apiToken !== '' && hash_equals($apiToken, $responderKey)) {
            $errors[] = 'WEBRESPONDER_KEY must not be the same value as NS_API_TOKEN.';
        }

        $apiCaFile = $get('NS_API_CA_FILE');
        if ($apiCaFile !== '') {
            $apiCaFile = self::resolvePath($apiCaFile, $projectRoot);
            if (!is_file($apiCaFile) || !is_readable($apiCaFile)) {
                $errors[] = sprintf('NS_API_CA_FILE does not exist or is not readable by PHP: %s', self::nativePath($apiCaFile));
            }
        }

        $appUrl = rtrim($get('APP_URL'), '/');
        if ($appUrl !== '' && !self::isHttpUrl($appUrl)) {
            $errors[] = 'APP_URL must be blank or a full URL such as https://sms.example.com/aatosms';
        }

        $defaultDomain = $get('NS_DEFAULT_DOMAIN');
        if ($defaultDomain !== '' && !AutoAttendant::isValidDomain($defaultDomain)) {
            $errors[] = 'NS_DEFAULT_DOMAIN is not a valid NetSapiens domain name.';
        }

        $smsOption = $get('SMS_SCRIPT_OPTION', '2');
        if (!AutoAttendant::isValidOption($smsOption)) {
            $errors[] = sprintf('SMS_SCRIPT_OPTION must be a single auto attendant key: 0-9 or * (found "%s").', $smsOption);
        }

        $smsUser = $get('SMS_USER');
        if ($smsUser !== '' && !AutoAttendant::isValidUser($smsUser)) {
            $errors[] = 'SMS_USER is not a valid user/extension.';
        }

        $ttsVoice = $get('TTS_VOICE');
        $ttsLanguage = $get('TTS_LANGUAGE');
        foreach (['TTS_VOICE' => $ttsVoice, 'TTS_LANGUAGE' => $ttsLanguage] as $key => $value) {
            if ($value !== '' && preg_match('/\A[A-Za-z0-9 ._-]{1,64}\z/', $value) !== 1) {
                $errors[] = sprintf('%s may only contain letters, digits, spaces and . _ -', $key);
            }
        }

        $logTimezone = $get('LOG_TIMEZONE');
        if ($logTimezone !== '' && !in_array($logTimezone, \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = sprintf('LOG_TIMEZONE "%s" is not a valid timezone name (example: America/New_York).', $logTimezone);
        }

        $prompts = [];
        foreach (self::DEFAULT_PROMPTS as $name => $default) {
            $text = $get('PROMPT_' . strtoupper($name), $default);
            if (strlen($text) > 1000) {
                $errors[] = sprintf('PROMPT_%s is longer than 1000 characters.', strtoupper($name));
            }
            $prompts[$name] = $text;
        }

        $known = array_merge(self::KNOWN_KEYS, array_map(static fn (string $name): string => 'PROMPT_' . strtoupper($name), array_keys(self::DEFAULT_PROMPTS)));
        $unknownKeys = array_values(array_diff(array_keys($values), $known));

        $config = new self(
            projectRoot: $projectRoot,
            settingsFile: $settingsFile,
            pointerFile: $pointerFile,
            apiUrl: $apiUrl,
            apiToken: $apiToken,
            apiTimeout: $integer('NS_API_TIMEOUT', 10, 2, 25),
            apiCaFile: $apiCaFile,
            defaultDomain: $defaultDomain,
            responderKey: $responderKey,
            appUrl: $appUrl,
            smsOption: $smsOption,
            smsUser: $smsUser,
            numberFormat: $choice('SMS_NUMBER_FORMAT', '11digit', self::NUMBER_FORMATS),
            limitPerDestination: $integer('SMS_LIMIT_PER_DESTINATION', 3, 0, 1000),
            limitWindowMinutes: $integer('SMS_LIMIT_WINDOW_MINUTES', 60, 1, 43200),
            digitStyle: $choice('CALLER_ID_DIGIT_STYLE', 'words', self::DIGIT_STYLES),
            ttsVoice: $ttsVoice,
            ttsLanguage: $ttsLanguage,
            inputTimeout: $integer('INPUT_TIMEOUT_SECONDS', 6, 1, 60),
            interdigitTimeout: $integer('INTERDIGIT_TIMEOUT_SECONDS', 5, 1, 30),
            maxAttempts: $integer('MAX_ATTEMPTS', 3, 1, 10),
            logLevel: $choice('LOG_LEVEL', 'info', self::LOG_LEVELS),
            logRetentionDays: $integer('LOG_RETENTION_DAYS', 30, 0, 3650),
            logTimezone: $logTimezone,
            storagePath: self::resolvePath($get('STORAGE_PATH', 'var'), $projectRoot),
            prompts: $prompts,
            unknownKeys: $unknownKeys,
        );

        if ($errors !== []) {
            throw new ConfigException('Invalid configuration: ' . implode(' ', $errors));
        }

        return $config;
    }

    /**
     * Address that the PBX is told to send the keys of a caller to. Without APP_URL it
     * is the path the script was reached at, on the same host. APP_URL may name the
     * folder that holds the script (https://host/aatosms) or the script itself
     * (https://host/aatosms/index.php).
     *
     * @param string $path address of the running script on this server (/aatosms/index.php), '' when unknown
     */
    public function scriptUrl(string $path): string
    {
        $script = $path !== '' ? $path : '/index.php';
        if ($this->appUrl === '') {
            return $script;
        }
        $namesScript = preg_match('~[^/]\.php\z~i', (string) parse_url($this->appUrl, PHP_URL_PATH)) === 1;

        return $namesScript ? $this->appUrl : $this->appUrl . '/' . basename($script);
    }

    /** Never expose secrets through var_dump()/print_r() of this object. */
    public function __debugInfo(): array
    {
        $data = get_object_vars($this);
        $data['apiToken'] = $this->apiToken === '' ? '' : '[redacted]';
        $data['responderKey'] = $this->responderKey === '' ? '' : '[redacted]';

        return $data;
    }

    /** Absolute paths are kept; relative paths are resolved against the project root. */
    public static function resolvePath(string $path, string $projectRoot): string
    {
        $isAbsolute = preg_match('/\A(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) === 1;
        $full = $isAbsolute ? $path : rtrim($projectRoot, '/\\') . '/' . $path;

        return rtrim($full, '/\\');
    }

    /** A path the way the operating system writes it (backslashes on Windows), for messages. */
    public static function nativePath(string $path): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? str_replace('/', '\\', $path) : $path;
    }

    private static function isHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && !isset($parts['user'])
            && preg_match('/[\s<>"\']/', $url) !== 1;
    }

    private static function isSecureOrLocal(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        return $scheme === 'https' || in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
