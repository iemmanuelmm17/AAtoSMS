<?php

declare(strict_types=1);

namespace AAtoSMS;

/** Small UTF-8 helpers that do not need the optional mbstring extension. */
final class Text
{
    /** Number of characters (not bytes); falls back to bytes for text that is not valid UTF-8. */
    public static function length(string $text): int
    {
        $count = preg_match_all('/./us', $text);

        return is_int($count) ? $count : strlen($text);
    }

    /** At most $max characters on one line, ending in "..." when something was cut off. */
    public static function shorten(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (self::length($text) <= $max) {
            return $text;
        }
        $keep = max(0, $max - 3);

        return (preg_match('/\A.{0,' . $keep . '}/us', $text, $match) === 1 ? $match[0] : substr($text, 0, $keep)) . '...';
    }
}
