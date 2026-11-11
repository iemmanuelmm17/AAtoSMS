<?php

declare(strict_types=1);

namespace AAtoSMS;

/**
 * Installation check: is PHP fit, are the settings valid, can the storage folder
 * be written, can the auto attendant be read, and (on request) can a text be sent.
 *
 * Runs in two places, with the same rules as a real call:
 *
 *   command line   php bin/check.php [AccountDomain=... AccountUser=... SMSNumber=... SendTo=...]
 *   web server     index.php?key=...&check=1[&AccountDomain=...&AccountUser=...]
 *
 * The web form matters because the web server often runs PHP with another
 * php.ini and another Windows account than the command line does.
 * Secrets are never printed.
 */
final class Diagnostics
{
    /** @var list<string> */
    private array $lines = [];

    private int $problems = 0;

    private int $warnings = 0;

    /** Address of the web entry script on this server (/aatosms/index.php); '' on the command line. */
    private string $webPath = '';

    /**
     * @param Config|null     $config already loaded settings; null = read the .env file
     * @param NetSapiens|null $api    API client to use; null = build one from the settings
     */
    public function __construct(
        private string $projectRoot,
        private ?Config $config = null,
        private ?NetSapiens $api = null,
    ) {
    }

    public function problems(): int
    {
        return $this->problems;
    }

    /**
     * @param Request $request   the values a call would carry: AccountDomain, AccountUser,
     *                           SMSNumber, SMSOption, SMSUser, AAUser, AAPrompt, plus SendTo
     * @param bool    $allowSend whether SendTo=<number> may send one real text
     */
    public function run(Request $request, bool $allowSend = false): string
    {
        $this->lines = ['AAtoSMS ' . VERSION . ' installation check', ''];
        $this->problems = 0;
        $this->warnings = 0;

        $this->checkPhp();
        $this->webPath = $request->path;
        if ($this->webPath !== '') {
            $this->checkWebAddress($this->webPath);
        }
        if ($this->checkSettings()) {
            $this->checkStorage();
            $this->describeCertificates();
            $this->checkCall($request, $allowSend);
        }

        $this->lines[] = '';
        $this->lines[] = match (true) {
            $this->problems > 0 => sprintf('Result: %d problem(s) to fix, %d warning(s).', $this->problems, $this->warnings),
            $this->warnings > 0 => sprintf('Result: no problems, %d warning(s).', $this->warnings),
            default => 'Result: no problems found.',
        };

        return implode("\n", $this->lines) . "\n";
    }

    private function checkPhp(): void
    {
        $this->section('PHP');
        $about = sprintf('PHP %s (%s, %s)', PHP_VERSION, PHP_SAPI, PHP_OS_FAMILY);
        PHP_VERSION_ID >= 80200 ? $this->ok($about) : $this->fail($about . ' is too old: PHP 8.2 or newer is needed.');

        $ini = php_ini_loaded_file();
        if (extension_loaded('curl')) {
            $curl = curl_version();
            $this->ok(sprintf('cURL %s with %s', $curl['version'] ?? '?', ($curl['ssl_version'] ?? '') !== '' ? $curl['ssl_version'] : 'no HTTPS support'));
        } else {
            $this->fail('The cURL extension is not enabled. Add "extension=curl" to ' . ($ini !== false ? $ini : 'php.ini') . ' and restart the web server.');
        }
        $this->note('php.ini: ' . ($ini !== false ? $ini : 'none loaded'));
    }

    /**
     * Only public/ is meant to be served. An address that goes through ".../public/"
     * shows that the project folder itself (with .env and var/) is inside the web site.
     */
    private function checkWebAddress(string $path): void
    {
        $this->section('Web server');
        if (preg_match('~\A(.*)/public/[^/]*\z~i', $path, $match) !== 1) {
            $this->ok('this page was reached as ' . $path);

            return;
        }
        $this->warn(sprintf('This page was reached as %s. The "public" in the address means that the whole', $path));
        $this->note('project folder is probably reachable from the web, private files included.');
        $this->note(sprintf('Test it: open %s/.env.example in this browser. An error page is the right answer.', $match[1]));
        $this->note('If the file is shown, make the web server serve only the "public" folder (README, "Web server").');
    }

    private function checkSettings(): bool
    {
        $this->section('Settings');
        if ($this->config === null) {
            try {
                $this->config = Config::load($this->projectRoot);
            } catch (ConfigException $error) {
                $this->fail($error->getMessage());

                return false;
            }
            $this->ok(Config::envFile($this->projectRoot) . ' is valid');
        } else {
            $this->ok('settings are valid');
        }

        $config = $this->config;
        if ($config->unknownKeys !== []) {
            $this->warn('Names in .env that this application does not know (misspelled?): ' . implode(', ', $config->unknownKeys));
        }
        $this->note('API ................. ' . $config->apiUrl);
        $this->note('Text comes from ..... auto attendant key ' . $config->smsOption);
        $this->note('Numbers sent as ..... ' . PhoneNumber::format('8452632561', $config->numberFormat) . ' (' . $config->numberFormat . ')');
        $this->note('Numbers spoken as ... ' . PhoneNumber::speak('8452632561', $config->digitStyle) . ' (' . $config->digitStyle . ')');
        $this->note('Limit ............... ' . ($config->limitPerDestination === 0
            ? 'none'
            : sprintf('%d text(s) per destination per %d minutes', $config->limitPerDestination, $config->limitWindowMinutes)));
        $callback = $config->appUrl . '/' . basename($this->webPath !== '' ? $this->webPath : '/index.php');
        $this->note('Callback URLs ....... ' . ($config->appUrl !== '' ? $callback : 'same host and path as the To-Web URL'));
        $this->note('Log ................. ' . $config->storagePath . '/logs (' . $config->logLevel . ')');

        // The keys a caller presses are sent to APP_URL. Seen from the web server, a wrong path can be spotted.
        $callbackPath = (string) parse_url($callback, PHP_URL_PATH);
        if ($config->appUrl !== '' && $this->webPath !== '' && strcasecmp($callbackPath, $this->webPath) !== 0) {
            $this->warn(sprintf('APP_URL makes the PBX send the keys of the caller to %s, but this page was reached as %s.', $callbackPath, $this->webPath));
            $this->note('Unless a proxy in front of this server translates the address, the keys will be lost:');
            $this->note('correct APP_URL in .env, or leave it blank.');
        }

        return true;
    }

    private function checkStorage(): void
    {
        $this->section('Storage');
        $failed = [];
        foreach (['logs', 'calls', 'sent'] as $name) {
            $folder = $this->config->storagePath . '/' . $name;
            $probe = $folder . '/check-' . bin2hex(random_bytes(4)) . '.tmp';
            $written = (is_dir($folder) || @mkdir($folder, 0700, true) || is_dir($folder)) && @file_put_contents($probe, 'x') === 1;
            if (!$written || !@unlink($probe)) {
                $failed[] = $folder;
            }
        }
        if ($failed === []) {
            $this->ok($this->config->storagePath . ' can be written by this PHP');
        } else {
            $this->fail('This PHP cannot write to: ' . implode(', ', $failed) . '. Give the account that runs PHP "Modify" permission on ' . $this->config->storagePath . '.');
        }
    }

    private function describeCertificates(): void
    {
        $this->section('HTTPS certificates');
        $curlIni = (string) ini_get('curl.cainfo');
        $opensslIni = (string) ini_get('openssl.cafile');
        if ($this->config->apiCaFile !== '') {
            $this->ok('checked against NS_API_CA_FILE: ' . $this->config->apiCaFile);
        } elseif ($opensslIni !== '' || $curlIni !== '') {
            $file = $opensslIni !== '' ? $opensslIni : $curlIni;
            is_file($file)
                ? $this->ok('checked against the CA bundle from php.ini: ' . $file)
                : $this->fail('php.ini points to a CA bundle that does not exist: ' . $file);
        } elseif (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $this->ok('checked against the Windows certificate store (no CA bundle is configured)');
        } else {
            $this->ok('checked against the default CA store of this PHP');
        }
    }

    private function checkCall(Request $request, bool $allowSend): void
    {
        if ($request->get('AccountUser') === '' && $request->get('AAUser') === '') {
            $this->section('Auto attendant');
            $this->note('Not tested. Add the values the PBX sends to also read an auto attendant, for example:');
            $this->note('  AccountDomain=1180.DynaCloud AccountUser=9000 SMSNumber=8457747000');

            return;
        }

        $api = $this->api ?? new NetSapiens($this->config->apiUrl, $this->config->apiToken, $this->config->apiTimeout, $this->config->apiCaFile);
        $message = null;
        $source = null;

        try {
            $source = CallPlan::source($request, $this->config);
            $this->section(sprintf('Auto attendant %s in %s', $source['aa_user'], $source['domain']));
            $autoAttendant = $api->autoAttendant($source['domain'], $source['aa_user'], $source['prompt']);
            $this->ok(sprintf('%s was read from the API', $source['prompt']));
            $this->listKeys($autoAttendant);
            $message = AutoAttendant::script($autoAttendant, $source['option']);
            $this->ok(sprintf('Key %s holds the text to send (%d characters):', $source['option'], Text::length($message)));
            foreach (preg_split('/\r\n|\n|\r/', wordwrap($message, 90)) ?: [] as $line) {
                $this->note('  ' . $line);
            }
        } catch (CallSetupException | AutoAttendantException $error) {
            if ($source === null) {
                $this->section('Auto attendant');
            }
            $this->fail($error->getMessage());
        } catch (ApiException $error) {
            $this->fail($error->getMessage() . ($error->responseBody !== '' ? ' - ' . $error->responseBody : ''));
            if ($error->hint() !== '') {
                $this->note($error->hint());
            }
        }
        if ($source === null) {
            return;
        }

        $sendTo = $request->get('SendTo');
        if ($request->get('SMSNumber') === '' && $request->get('NmsDnis') === '' && $sendTo === '') {
            return;
        }

        $this->section('Sending');
        try {
            $sender = CallPlan::sender($request, $this->config, $source['aa_user']);
        } catch (CallSetupException $error) {
            $this->fail($error->getMessage());

            return;
        }
        $from = PhoneNumber::format($sender['from'], $this->config->numberFormat);
        $this->ok(sprintf('Texts are sent from %s as user %s', $from, $sender['sms_user']));

        if ($sendTo === '') {
            return;
        }
        if (!$allowSend) {
            $this->warn('SendTo is ignored here: a test text can only be sent from the command line (php bin/check.php ... SendTo=<number>).');

            return;
        }
        $destination = PhoneNumber::normalize($sendTo);
        if ($destination === null) {
            $this->fail(sprintf('SendTo "%s" is not a valid 10-digit number.', $sendTo));

            return;
        }
        if ($message === null) {
            $this->fail('No test text was sent because the text could not be read (see above).');

            return;
        }
        try {
            $result = $api->sendSms($source['domain'], $sender['sms_user'], PhoneNumber::format($destination, $this->config->numberFormat), $from, $message);
            $this->ok(sprintf('One real text was sent to %s (HTTP %d %s)', PhoneNumber::format($destination, $this->config->numberFormat), $result['status'], $result['body']));
        } catch (ApiException $error) {
            $this->fail($error->getMessage() . ($error->responseBody !== '' ? ' - ' . $error->responseBody : ''));
            if ($error->hint() !== '') {
                $this->note($error->hint());
            }
        }
    }

    /**
     * One line per key of the auto attendant, to see at a glance what is where.
     *
     * @param array<array-key, mixed> $autoAttendant
     */
    private function listKeys(array $autoAttendant): void
    {
        $menu = is_array($autoAttendant['auto-attendant'] ?? null) ? $autoAttendant['auto-attendant'] : [];
        foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '*'] as $key) {
            $entry = $menu['option-' . $key] ?? null;
            if (!is_array($entry) || $entry === []) {
                continue;
            }
            $what = is_string($entry['destination-application'] ?? null) ? $entry['destination-application'] : (isset($entry['auto-attendant']) ? 'tier' : '?');
            if (is_scalar($entry['destination-user'] ?? null) && (string) $entry['destination-user'] !== '') {
                $what .= ' ' . $entry['destination-user'];
            }
            try {
                $what .= ': "' . Text::shorten(AutoAttendant::script($autoAttendant, $key), 60) . '"';
            } catch (AutoAttendantException) {
                // This key has no message text; the destination alone is shown.
            }
            $this->note(sprintf('  key %s  %s', $key, $what));
        }
    }

    private function section(string $title): void
    {
        $this->lines[] = $title;
    }

    private function ok(string $text): void
    {
        $this->lines[] = '  [ OK ] ' . $text;
    }

    private function warn(string $text): void
    {
        $this->warnings++;
        $this->lines[] = '  [WARN] ' . $text;
    }

    private function fail(string $text): void
    {
        $this->problems++;
        $this->lines[] = '  [FAIL] ' . $text;
    }

    private function note(string $text): void
    {
        $this->lines[] = '         ' . $text;
    }
}
