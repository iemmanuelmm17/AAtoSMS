<?php

declare(strict_types=1);

namespace AAtoSMS;

/**
 * Writes one line per event to a daily file: <directory>/aatosms-YYYY-MM-DD.log
 *
 *   2026-11-11T09:30:02-05:00 ERROR   [4f9a2c1b] SMS send failed {"http_status":401}
 *
 * The bracketed value is the call reference; the same value appears as an XML
 * comment in every response, so a call trace can be matched to its log lines.
 * Secrets registered with addSecret() never reach the file. If the file cannot
 * be written, the line goes to PHP's own error log so it is not lost.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private string $callRef = '-';

    /** @var list<string> */
    private array $secrets = [];

    private bool $fileUnavailable = false;

    public function __construct(
        private string $directory,
        private string $level = 'info',
        private ?\DateTimeZone $timezone = null,
    ) {
        if (!isset(self::LEVELS[$this->level])) {
            $this->level = 'info';
        }
    }

    public function setCallRef(string $ref): void
    {
        $this->callRef = preg_replace('/[^A-Za-z0-9]/', '', $ref) ?: '-';
    }

    /** Registers a value (API token, responder key) that must never be written. */
    public function addSecret(string $secret): void
    {
        if (strlen($secret) >= 6) {
            $this->secrets[] = $secret;
        }
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!isset(self::LEVELS[$level]) || self::LEVELS[$level] < self::LEVELS[$this->level]) {
            return;
        }

        $now = new \DateTimeImmutable('now', $this->timezone);
        $line = $now->format('Y-m-d\TH:i:sP') . ' ' . str_pad(strtoupper($level), 7) . ' [' . $this->callRef . '] '
            . (preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '');
        if ($context !== []) {
            $json = json_encode($context, self::JSON_FLAGS);
            $line .= ' ' . ($json === false ? '{"context":"not encodable"}' : $json);
        }
        $line = $this->redact($line);

        if (!$this->fileUnavailable) {
            $file = $this->directory . '/aatosms-' . $now->format('Y-m-d') . '.log';
            if ((is_dir($this->directory) || @mkdir($this->directory, 0700, true) || is_dir($this->directory))
                && @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) !== false) {
                return;
            }
            $this->fileUnavailable = true;
            error_log('AAtoSMS: cannot write to the log folder ' . $this->directory . ' - check that the web server account may write there.');
        }
        error_log('AAtoSMS: ' . $line);
    }

    /**
     * Where an exception came from, as log context.
     *
     * @return array{exception:string, at:string}
     */
    public static function describe(\Throwable $error): array
    {
        return [
            'exception' => $error::class,
            'at' => basename($error->getFile()) . ':' . $error->getLine(),
        ];
    }

    /** Deletes daily log files older than $retentionDays (0 keeps everything). */
    public function prune(int $retentionDays, ?int $now = null): int
    {
        if ($retentionDays <= 0 || !is_dir($this->directory)) {
            return 0;
        }
        $cutoff = ($now ?? time()) - $retentionDays * 86400;
        $deleted = 0;
        foreach (@scandir($this->directory) ?: [] as $name) {
            if (preg_match('/\Aaatosms-\d{4}-\d{2}-\d{2}\.log\z/', $name) !== 1) {
                continue;
            }
            $file = $this->directory . '/' . $name;
            $modified = @filemtime($file);
            if ($modified !== false && $modified < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function redact(string $line): string
    {
        foreach ($this->secrets as $secret) {
            $line = str_replace($secret, '[redacted]', $line);
        }
        $line = preg_replace('/\bBearer\s+[^\s"\',;]+/i', 'Bearer [redacted]', $line) ?? $line;

        // key= is the responder key; call= is the private id of a call in a callback URL.
        return preg_replace('/\b(key|call|token|access_token)=[^\s&"\'<\\\\]+/i', '$1=[redacted]', $line) ?? $line;
    }
}
