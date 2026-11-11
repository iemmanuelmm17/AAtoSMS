<?php

declare(strict_types=1);

namespace AAtoSMS;

final class CallStoreException extends \RuntimeException
{
}

/**
 * The private state of one call, held under an exclusive file lock.
 *
 * The lock lasts until close() (or the end of the request), so a repeated
 * request for the same call waits for the first one to finish and then sees
 * its saved answer instead of running the step a second time.
 */
final class CallSession
{
    /** @var resource|null */
    private $handle;

    /**
     * @param array<string, mixed> $state
     * @param resource             $handle open and exclusively locked state file
     */
    public function __construct(public readonly string $id, public array $state, $handle)
    {
        $this->handle = $handle;
    }

    public function save(): void
    {
        if ($this->handle === null) {
            throw new CallStoreException('The call state is already closed.');
        }
        $json = json_encode($this->state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (!rewind($this->handle) || !ftruncate($this->handle, 0) || fwrite($this->handle, $json) !== strlen($json) || !fflush($this->handle)) {
            throw new CallStoreException('The call state could not be saved (disk full or no write permission?).');
        }
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}

/**
 * Keeps one small JSON file per call in <storage>/calls. The file name is a
 * random 128-bit id that only this application and the PBX (through the
 * callback URL) ever see.
 */
final class CallStore
{
    public function __construct(private string $directory)
    {
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/\A[a-f0-9]{32}\z/', $id) === 1;
    }

    /** @param array<string, mixed> $state */
    public function create(array $state): CallSession
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new CallStoreException(sprintf('Cannot create the call state folder %s - check that the web server account may write there.', $this->directory));
        }

        $id = bin2hex(random_bytes(16));
        $handle = @fopen($this->path($id), 'x+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new CallStoreException(sprintf('Cannot create a call state file in %s - check that the web server account may write there.', $this->directory));
        }

        $session = new CallSession($id, $state, $handle);
        $session->save();

        return $session;
    }

    /** Opens and locks an existing call; null when the id is unknown or expired. */
    public function open(string $id): ?CallSession
    {
        if (!self::isValidId($id) || !is_file($this->path($id))) {
            return null;
        }
        $handle = @fopen($this->path($id), 'r+b');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new CallStoreException('The call state could not be locked.');
        }

        $state = json_decode((string) stream_get_contents($handle), true);
        if (!is_array($state) || !isset($state['step'], $state['stage'], $state['ref'])) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new CallStoreException('The call state file is damaged.');
        }

        return new CallSession($id, $state, $handle);
    }

    /** Deletes the state of calls that started more than $maxAgeSeconds ago. */
    public function prune(int $maxAgeSeconds, ?int $now = null): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }
        $cutoff = ($now ?? time()) - $maxAgeSeconds;
        $deleted = 0;
        foreach (@scandir($this->directory) ?: [] as $name) {
            if (preg_match('/\A[a-f0-9]{32}\.json\z/', $name) !== 1) {
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

    private function path(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }
}
