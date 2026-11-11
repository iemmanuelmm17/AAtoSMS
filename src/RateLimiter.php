<?php

declare(strict_types=1);

namespace AAtoSMS;

/**
 * Limits how many texts one destination number can receive in a time window,
 * so the line cannot be used to flood somebody else's phone. Callers may type
 * any number, so this is the only brake on that.
 *
 * One file per destination in <storage>/sent holds the times of recent texts.
 * A limit of 0 switches the limiter off.
 */
final class RateLimiter
{
    public function __construct(
        private string $directory,
        private int $limit,
        private int $windowSeconds,
    ) {
    }

    /**
     * Counts one more text to $destination, unless the limit is reached. Checking
     * and counting happen under one file lock, so calls that arrive at the same
     * moment cannot exceed the limit together.
     *
     * @return bool|null true:  counted, the text may be sent
     *                   false: the limit is reached, nothing was counted
     *                   null:  the text may be sent, but it could not be counted (storage not writable)
     */
    public function reserve(string $destination, ?int $now = null): ?bool
    {
        $file = $this->path($destination);
        if ($this->limit <= 0 || $file === null) {
            return true;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return null;
        }
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        $now ??= time();
        $times = $this->recent((string) stream_get_contents($handle), $now);
        $granted = count($times) < $this->limit;
        $stored = true;
        if ($granted) {
            $times[] = $now;
            $stored = self::write($handle, $times);
        }
        flock($handle, LOCK_UN);
        fclose($handle);

        return $stored ? $granted : null;
    }

    /** Takes back what reserve() counted at $time, for a text that was not sent after all. */
    public function release(string $destination, int $time): void
    {
        $file = $this->path($destination);
        if ($this->limit <= 0 || $file === null || !is_file($file)) {
            return;
        }
        $handle = @fopen($file, 'r+b');
        if ($handle === false) {
            return;
        }
        if (flock($handle, LOCK_EX)) {
            // Only that one entry is removed; everything else in the file stays as it is.
            $times = json_decode((string) stream_get_contents($handle), true);
            $index = is_array($times) ? array_search($time, $times, true) : false;
            if ($index !== false) {
                unset($times[$index]);
                self::write($handle, array_values($times));
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    /** Deletes files whose newest entry is older than the window. */
    public function prune(?int $now = null): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }
        $cutoff = ($now ?? time()) - $this->windowSeconds;
        $deleted = 0;
        foreach (@scandir($this->directory) ?: [] as $name) {
            if (preg_match('/\A[0-9]{7,15}\.json\z/', $name) !== 1) {
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

    /** @return list<int> send times that are still inside the window */
    private function recent(string $json, int $now): array
    {
        $times = json_decode($json, true);
        if (!is_array($times)) {
            return [];
        }
        $cutoff = $now - $this->windowSeconds;

        return array_values(array_filter($times, static fn (mixed $time): bool => is_int($time) && $time > $cutoff && $time <= $now + 60));
    }

    /**
     * @param resource    $handle open and exclusively locked file
     * @param list<mixed> $times
     */
    private static function write($handle, array $times): bool
    {
        $json = (string) json_encode($times);

        return rewind($handle) && ftruncate($handle, 0) && fwrite($handle, $json) === strlen($json) && fflush($handle);
    }

    private function path(string $destination): ?string
    {
        return preg_match('/\A[0-9]{7,15}\z/', $destination) === 1 ? $this->directory . '/' . $destination . '.json' : null;
    }
}
