<?php

declare(strict_types=1);

namespace AAtoSMS;

/** US/Canada (NANP) phone numbers: validation, API formatting and how they are spoken. */
final class PhoneNumber
{
    private const WORDS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];

    /**
     * Returns the 10-digit number, or null when $raw is not a valid US/Canada
     * number. Accepts 10 digits, or 11 digits starting with 1, with optional
     * "+" and punctuation: "8452632561", "18452632561", "+1 (845) 263-2561".
     * Extensions, anonymous/blocked caller IDs and names all return null.
     */
    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/\A\+?[0-9().\s-]+\z/', $raw) !== 1) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        // Area code and exchange never start with 0 or 1.
        return preg_match('/\A[2-9][0-9]{2}[2-9][0-9]{6}\z/', $digits) === 1 ? $digits : null;
    }

    /** Formats a normalized 10-digit number the way the SMS API should receive it. */
    public static function format(string $tenDigits, string $format): string
    {
        return match ($format) {
            '10digit' => $tenDigits,
            'e164' => '+1' . $tenDigits,
            default => '1' . $tenDigits,
        };
    }

    /**
     * Text handed to text-to-speech so a number is read digit by digit instead
     * of as one very large number. For 8452632561:
     *
     *   words    eight, four, five, two, six, three, two, five, six, one
     *   commas   8, 4, 5, 2, 6, 3, 2, 5, 6, 1
     *   hyphens  8-4-5-2-6-3-2-5-6-1
     *   spaces   8 4 5 2 6 3 2 5 6 1
     */
    public static function speak(string $number, string $style = 'words'): string
    {
        $digits = str_split(preg_replace('/[^0-9]/', '', $number) ?? '');
        if ($digits === ['']) {
            return '';
        }

        return match ($style) {
            'commas' => implode(', ', $digits),
            'hyphens' => implode('-', $digits),
            'spaces' => implode(' ', $digits),
            default => implode(', ', array_map(static fn (string $digit): string => self::WORDS[(int) $digit], $digits)),
        };
    }
}
