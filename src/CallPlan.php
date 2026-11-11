<?php

declare(strict_types=1);

namespace AAtoSMS;

/** The request cannot be turned into a call: a required value is missing or malformed. */
final class CallSetupException extends \RuntimeException
{
}

/**
 * Works out, from the parameters of a request, where the text comes from and
 * how it has to be sent. Used for real calls and by the installation check, so
 * both apply exactly the same rules.
 *
 *   Value                Taken from (first one that is not blank)
 *   -------------------  -------------------------------------------------------
 *   domain               AccountDomain, NS_DEFAULT_DOMAIN in .env
 *   auto attendant       AAUser, AccountUser
 *   prompt name          AAPrompt, the 3/4-digit rule applied to the extension
 *   key with the text    SMSOption, SMS_SCRIPT_OPTION in .env (default 2)
 *   user that sends      SMSUser, SMS_USER in .env, the auto attendant extension
 *   number to send from  SMSNumber, NmsDnis
 */
final class CallPlan
{
    /**
     * Which auto attendant message holds the text.
     *
     * @return array{domain:string, aa_user:string, prompt:string, option:string}
     */
    public static function source(Request $request, Config $config): array
    {
        $domain = self::firstSet($request->get('AccountDomain'), $config->defaultDomain);
        if ($domain === '') {
            throw new CallSetupException('The request has no AccountDomain and NS_DEFAULT_DOMAIN is not set in .env.');
        }
        if (!AutoAttendant::isValidDomain($domain)) {
            throw new CallSetupException(sprintf('AccountDomain "%s" is not a valid domain name.', $domain));
        }

        $aaUser = self::firstSet($request->get('AAUser'), $request->get('AccountUser'));
        if ($aaUser === '') {
            throw new CallSetupException('The request has no AccountUser (the auto attendant extension).');
        }
        if (!AutoAttendant::isValidUser($aaUser)) {
            throw new CallSetupException(sprintf('Auto attendant extension "%s" is not valid.', $aaUser));
        }

        $prompt = $request->get('AAPrompt');
        if ($prompt === '') {
            try {
                $prompt = AutoAttendant::promptName($aaUser);
            } catch (AutoAttendantException $error) {
                throw new CallSetupException($error->getMessage());
            }
        } elseif (!AutoAttendant::isValidPrompt($prompt)) {
            throw new CallSetupException(sprintf('AAPrompt "%s" is not a valid prompt name.', $prompt));
        }

        $option = self::firstSet($request->get('SMSOption'), $config->smsOption);
        if (!AutoAttendant::isValidOption($option)) {
            throw new CallSetupException(sprintf('SMSOption "%s" is not an auto attendant key (0-9 or *).', $option));
        }

        return ['domain' => $domain, 'aa_user' => $aaUser, 'prompt' => $prompt, 'option' => $option];
    }

    /**
     * Who sends the text, and from which number.
     *
     * @return array{sms_user:string, from:string} "from" is a 10-digit number
     */
    public static function sender(Request $request, Config $config, string $aaUser): array
    {
        $smsUser = self::firstSet($request->get('SMSUser'), $config->smsUser, $aaUser);
        if (!AutoAttendant::isValidUser($smsUser)) {
            throw new CallSetupException(sprintf('SMSUser "%s" is not a valid user.', $smsUser));
        }

        // The text is sent from SMSNumber; when that is blank, from the number that was dialed.
        $smsNumber = $request->get('SMSNumber');
        $from = PhoneNumber::normalize($smsNumber !== '' ? $smsNumber : $request->get('NmsDnis'));
        if ($from === null) {
            throw new CallSetupException($smsNumber !== ''
                ? sprintf('SMSNumber "%s" is not a valid 10-digit number.', $smsNumber)
                : sprintf('SMSNumber is blank and NmsDnis "%s" is not a valid 10-digit number, so there is no number to send from. Add SMSNumber=<number> to the To-Web URL.', $request->get('NmsDnis')));
        }

        return ['sms_user' => $smsUser, 'from' => $from];
    }

    /** First value that is not blank. "0" counts as a value. */
    private static function firstSet(string ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
