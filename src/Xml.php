<?php

declare(strict_types=1);

namespace AAtoSMS;

/**
 * Builds NetSapiens Web Responder XML: the v44.1+ verbs Response, Say, Gather and
 * Hangup, plus Forward. Every piece of text and every attribute value is XML-escaped here.
 */
final class Xml
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_XML1, 'UTF-8');
    }

    /** Text-to-speech. Blank voice/language use the defaults of the PBX. */
    public static function say(string $text, string $voice = '', string $language = ''): string
    {
        $attributes = ($voice !== '' ? ' voice="' . self::escape($voice) . '"' : '')
            . ($language !== '' ? ' language="' . self::escape($language) . '"' : '');

        return '<Say' . $attributes . '>' . self::escape($text) . '</Say>';
    }

    /**
     * Plays $inner while collecting up to $numDigits keys, then requests $action
     * with the keys in the "Digits" parameter.
     */
    public static function gather(string $action, int $numDigits, int $timeout, int $digitEndTimeout, string $inner): string
    {
        return '<Gather input="dtmf" numDigits="' . $numDigits . '" timeout="' . $timeout . '" digitEndTimeout="' . $digitEndTimeout
            . '" action="' . self::escape($action) . '">' . $inner . '</Gather>';
    }

    public static function hangup(): string
    {
        return '<Hangup/>';
    }

    /**
     * Hands the call to $destination, an extension or a number that the PBX looks up in the
     * dial plan of the user who owns the call. The Web Responder is not asked again.
     */
    public static function forward(string $destination): string
    {
        return '<Forward>' . self::escape($destination) . '</Forward>';
    }

    /** Wraps verbs in <Response>. $ref ties the document to its log lines. */
    public static function document(string $ref, string ...$verbs): string
    {
        $ref = preg_replace('/[^A-Za-z0-9]/', '', $ref) ?? '';

        return '<?xml version="1.0" encoding="UTF-8"?><Response><!-- AAtoSMS ' . VERSION . ($ref !== '' ? ' ref=' . $ref : '') . ' -->'
            . implode('', $verbs) . '</Response>';
    }
}
