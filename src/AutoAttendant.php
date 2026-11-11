<?php

declare(strict_types=1);

namespace AAtoSMS;

final class AutoAttendantException extends \RuntimeException
{
}

/** Auto attendant rules: the prompt name of an extension and the SMS text of an option. */
final class AutoAttendant
{
    public static function isValidDomain(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $value) === 1;
    }

    public static function isValidUser(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,15}\z/', $value) === 1;
    }

    public static function isValidPrompt(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $value) === 1;
    }

    /** A single auto attendant key: 0-9 or *. */
    public static function isValidOption(string $value): bool
    {
        return preg_match('/\A[0-9*]\z/', $value) === 1;
    }

    /**
     * Prompt name of an auto attendant extension:
     *
     *   3-digit extension  Prompt_xxx001    (123  -> Prompt_123001)
     *   4-digit extension  Prompt_xxxx01    (9000 -> Prompt_900001)
     */
    public static function promptName(string $extension): string
    {
        if (preg_match('/\A[0-9]{3}\z/', $extension) === 1) {
            return 'Prompt_' . $extension . '001';
        }
        if (preg_match('/\A[0-9]{4}\z/', $extension) === 1) {
            return 'Prompt_' . $extension . '01';
        }

        throw new AutoAttendantException(sprintf(
            'Cannot build the prompt name for auto attendant extension "%s": the naming rule only covers 3-digit and 4-digit extensions. Add AAPrompt=<prompt name> to the To-Web URL for this auto attendant.',
            $extension
        ));
    }

    /**
     * Returns the text of the message played by one key of the auto attendant
     * ("auto-attendant" > "option-N" > "audio" > "file-script-text").
     *
     * @param array<array-key, mixed> $autoAttendant decoded response of GET .../autoattendants/{prompt}
     */
    public static function script(array $autoAttendant, string $option): string
    {
        $menu = $autoAttendant['auto-attendant'] ?? null;
        if (!is_array($menu)) {
            throw new AutoAttendantException('The API response has no "auto-attendant" section.');
        }

        $entry = $menu['option-' . $option] ?? null;
        if (!is_array($entry) || $entry === []) {
            throw new AutoAttendantException(sprintf('The auto attendant has nothing assigned to key %s.', $option));
        }

        $about = '';
        foreach (['destination-application', 'description'] as $field) {
            if (is_string($entry[$field] ?? null) && $entry[$field] !== '') {
                $about .= sprintf(' %s: "%s".', $field, $entry[$field]);
            }
        }

        // "audio" is one object when a message exists, [] when none is set. A list of
        // objects is accepted too and read in "ordinal-order".
        $audio = $entry['audio'] ?? null;
        if (!is_array($audio) || $audio === []) {
            throw new AutoAttendantException(sprintf('Key %s of the auto attendant has no message audio.%s', $option, $about));
        }
        $files = array_is_list($audio) ? array_values(array_filter($audio, 'is_array')) : [$audio];
        usort($files, static fn (array $a, array $b): int => (int) ($a['ordinal-order'] ?? 0) <=> (int) ($b['ordinal-order'] ?? 0));

        $parts = [];
        foreach ($files as $file) {
            $text = $file['file-script-text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $parts[] = trim($text);
            }
        }
        if ($parts === []) {
            throw new AutoAttendantException(sprintf(
                'The message on key %s of the auto attendant has no script text ("file-script-text" is empty), so there is nothing to send. Create that message with text-to-speech: its script is the text of the SMS.%s',
                $option,
                $about
            ));
        }

        return implode(' ', $parts);
    }
}
