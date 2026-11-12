<?php

declare(strict_types=1);

namespace AAtoSMS;

/**
 * The conversation with the caller. Each stage plays one prompt and waits for keys.
 *
 *   menu     "...the number you are calling from, <number>, press 1. To enter a
 *            different mobile number, press 2."
 *              1 -> send to the caller's number          2 -> enter
 *   enter    "Please enter the ten digit mobile number..."
 *              a valid number -> confirm
 *   confirm  "You entered <number>. ... press 1. ... press 2."
 *              1 -> send to the entered number           2 -> enter
 *   done     the text was sent (or could not be) and the call is hung up
 *
 * With repeat=yes in the To-Web URL, a caller whose text was sent is not hung up
 * but handed back to the auto attendant, and hears its main menu again.
 *
 * No answer or a wrong key repeats the prompt; after MAX_ATTEMPTS tries in a row
 * the caller hears a goodbye and the call ends without a text.
 *
 * Ten keys are collected for a number. Two habits make a caller press one more,
 * and both are understood: the leading 1 (the eleventh key is then awaited in
 * silence) and the pound key at the end (it is not taken as an answer).
 */
final class CallFlow
{
    /** Hard stop for a call that keeps going back and forth. */
    private const MAX_STEPS = 40;

    public function __construct(
        private Config $config,
        private NetSapiens $api,
        private RateLimiter $limiter,
        private Logger $logger,
    ) {
    }

    /**
     * First prompt of a new call.
     *
     * @param array<string, mixed> $state
     */
    public function start(array &$state, string $action): string
    {
        if ($state['caller'] !== '') {
            $state['stage'] = 'menu';

            return $this->ask($state, $action);
        }

        // No usable caller ID (blocked, or an internal extension): ask for a number right away.
        $state['stage'] = 'enter';

        return $this->ask($state, $action, $this->config->prompts['no_caller_id']);
    }

    /**
     * Handles the keys pressed at the current stage and returns the next XML document.
     *
     * @param array<string, mixed> $state
     * @param callable(): void     $checkpoint saves $state to disk immediately
     */
    public function advance(array &$state, string $digits, string $action, callable $checkpoint): string
    {
        if ($state['stage'] === 'done') {
            return Xml::document($state['ref'], Xml::hangup());
        }
        if ($state['step'] >= self::MAX_STEPS) {
            $this->logger->warning('Call ended without a text: too many steps', ['stage' => $state['stage']]);

            return $this->finish($state, 'goodbye');
        }

        // People often end a number with the pound key out of habit.
        $keys = rtrim(preg_replace('/\s+/', '', $digits) ?? '', '#');
        $problem = $digits === '' ? 'no_input' : 'invalid_choice';
        $this->logger->debug('Keys received', ['stage' => $state['stage'], 'digits' => $digits]);

        if ($state['stage'] === 'enter') {
            $typed = (string) ($state['typed'] ?? '');
            $state['typed'] = '';
            if ($typed === '' && preg_match('/\A1[0-9]{9}\z/', $keys) === 1) {
                // Ten keys that start with 1: the number is being typed as 1 + area code + number,
                // which is one key more than was collected. Wait in silence for the last key.
                $state['typed'] = $keys;

                return $this->awaitLastKey($state, $action);
            }

            $number = preg_match('/\A[0-9]+\z/', $typed . $keys) === 1 ? PhoneNumber::normalize($typed . $keys) : null;
            if ($number === null) {
                return $this->retry($state, $action, $digits === '' && $typed === '' ? 'no_input' : 'invalid_number');
            }
            $state['pending'] = $number;

            return $this->moveTo($state, 'confirm', $action);
        }

        if ($state['stage'] === 'confirm' && $keys === '' && $digits !== '' && !($state['pound'] ?? false)) {
            // The pound key pressed after the last digit arrives here, as the answer to a
            // question that was not heard yet. It ended the number; ask again, once.
            $state['pound'] = true;

            return $this->ask($state, $action);
        }

        if ($keys === '1') {
            return $this->send($state, $state['stage'] === 'confirm' ? $state['pending'] : $state['caller'], $checkpoint);
        }
        if ($keys === '2') {
            $state['pending'] = '';

            return $this->moveTo($state, 'enter', $action);
        }

        return $this->retry($state, $action, $problem);
    }

    /** Spoken apology plus hang up, for a call that cannot even start. */
    public function failure(string $ref): string
    {
        return Xml::document($ref, $this->say($this->config->prompts['error']), Xml::hangup());
    }

    /** @param array<string, mixed> $state */
    private function moveTo(array &$state, string $stage, string $action): string
    {
        $state['stage'] = $stage;
        $state['attempts'] = 0;
        $state['pound'] = false;

        return $this->ask($state, $action);
    }

    /** @param array<string, mixed> $state */
    private function retry(array &$state, string $action, string $problem): string
    {
        $state['attempts']++;
        if ($state['attempts'] >= $this->config->maxAttempts) {
            $this->logger->info('Call ended without a text: no valid answer', ['stage' => $state['stage'], 'attempts' => $state['attempts'], 'last' => $problem]);

            return $this->finish($state, 'goodbye');
        }

        return $this->ask($state, $action, $this->config->prompts[$problem]);
    }

    /**
     * Sends the text exactly once per call, then says the result and ends the call
     * (or, with repeat=yes and a text that was sent, hands it back to the auto attendant).
     *
     * @param array<string, mixed> $state
     * @param callable(): void     $checkpoint
     */
    private function send(array &$state, string $destination, callable $checkpoint): string
    {
        if ($state['sms'] !== 'none') {
            // This call already reached the sending step once (the request was repeated
            // after an interruption). The text is never sent a second time.
            $this->logger->warning('Send step repeated; not sending again', ['previous_result' => $state['sms']]);

            return $this->finish($state, match ($state['sms']) {
                'sent' => 'sent',
                'limited' => 'limit',
                default => 'error',
            });
        }

        $details = [
            'to' => PhoneNumber::format($destination, $this->config->numberFormat),
            'from' => PhoneNumber::format($state['from'], $this->config->numberFormat),
            'domain' => $state['domain'],
            'sms_user' => $state['sms_user'],
            'entered_by_caller' => $state['stage'] === 'confirm',
        ];

        // Counted before sending: calls that arrive together cannot exceed the limit.
        $now = time();
        $counted = $this->limiter->reserve($destination, $now);
        if ($counted === false) {
            $state['sms'] = 'limited';
            $this->logger->warning('Text not sent: limit for this destination reached', $details + [
                'limit' => $this->config->limitPerDestination,
                'window_minutes' => $this->config->limitWindowMinutes,
            ]);

            return $this->finish($state, 'limit');
        }
        if ($counted === null) {
            $this->logger->warning('This text cannot be counted for the per-destination limit (storage not writable?)');
        }

        // Written to disk before the API call, so that no repeated request can send again.
        $state['sms'] = 'sending';
        $state['destination'] = $destination;
        $checkpoint();

        try {
            $result = $this->api->sendSms($state['domain'], $state['sms_user'], $details['to'], $details['from'], $state['message']);
        } catch (ApiException $error) {
            $state['sms'] = 'failed';
            if ($counted === true) {
                $this->limiter->release($destination, $now); // a text that failed does not count
            }
            $this->logger->error('Text not sent: ' . $error->getMessage(), $details + [
                'http_status' => $error->status,
                'api_response' => $error->responseBody,
                'hint' => $error->hint(),
            ]);

            return $this->finish($state, 'error');
        }

        $state['sms'] = 'sent';
        $this->logger->info('Text sent', $details + [
            'http_status' => $result['status'],
            'api_response' => $result['body'],
            'characters' => Text::length($state['message']),
        ]);

        return $this->finish($state, 'sent');
    }

    /** @param array<string, mixed> $state */
    private function ask(array $state, string $action, string $preface = ''): string
    {
        [$text, $numDigits] = match ($state['stage']) {
            'menu' => [$this->withNumber('menu', $state['caller']), 1],
            'confirm' => [$this->withNumber('confirm', $state['pending']), 1],
            default => [$this->config->prompts['enter_number'], 10],
        };
        if ($preface !== '') {
            $text = $preface . ' ' . $text;
        }

        return Xml::document(
            $state['ref'],
            Xml::gather($action, $numDigits, $this->config->inputTimeout, $this->config->interdigitTimeout, $this->say($text))
        );
    }

    /**
     * No prompt: waits, as long as between any two keys, for the eleventh key of a
     * number that is typed with the leading 1.
     *
     * @param array<string, mixed> $state
     */
    private function awaitLastKey(array $state, string $action): string
    {
        $seconds = $this->config->interdigitTimeout;

        return Xml::document($state['ref'], Xml::gather($action, 1, $seconds, $seconds, ''));
    }

    /**
     * Last answer of a call: says how it went and hangs up. Only a text that was sent
     * together with repeat=yes ends differently: the call goes back to the auto attendant.
     *
     * @param array<string, mixed> $state
     */
    private function finish(array &$state, string $prompt): string
    {
        $state['stage'] = 'done';
        if ($prompt === 'sent' && ($state['repeat'] ?? false) === true) {
            $this->logger->info('Forwarding the call back to the auto attendant', ['extension' => $state['aa_user']]);

            return Xml::document($state['ref'], $this->say($this->config->prompts['sent_repeat']), Xml::forward((string) $state['aa_user']));
        }

        return Xml::document($state['ref'], $this->say($this->config->prompts[$prompt]), Xml::hangup());
    }

    private function withNumber(string $prompt, string $number): string
    {
        return str_replace('{number}', PhoneNumber::speak($number, $this->config->digitStyle), $this->config->prompts[$prompt]);
    }

    private function say(string $text): string
    {
        return Xml::say($text, $this->config->ttsVoice, $this->config->ttsLanguage);
    }
}
