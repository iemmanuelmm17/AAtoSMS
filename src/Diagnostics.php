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

    /** Without cURL the API cannot be reached, so those parts of the check are left out. */
    private bool $curl = false;

    /** @var array<string, mixed> see PhpExtension::environment() */
    private array $php;

    /**
     * @param Config|null               $config already loaded settings; null = read the .env file
     * @param NetSapiens|null           $api    API client to use; null = build one from the settings
     * @param array<string, mixed>|null $php    what is known about the running PHP; null = find out
     */
    public function __construct(
        private string $projectRoot,
        private ?Config $config = null,
        private ?NetSapiens $api = null,
        ?array $php = null,
    ) {
        $this->php = $php ?? PhpExtension::environment();
    }

    public function problems(): int
    {
        return $this->problems;
    }

    /**
     * @param Request $request   the values a call would carry: AccountDomain, AccountUser,
     *                           SMSNumber, option, SMSUser, AAUser, AAPrompt, repeat, plus SendTo
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
            if ($this->curl) {
                $this->describeCertificates();
            }
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
        $php = $this->php;
        $this->section('PHP');
        $about = sprintf('PHP %s (%s, %s, %s)', $php['version'], $php['sapi'], PHP_OS_FAMILY, $php['thread_safe'] ? 'thread safe' : 'non thread safe');
        PHP_VERSION_ID >= 80200 ? $this->ok($about) : $this->fail($about . ' is too old: PHP 8.2 or newer is needed.');
        if ($php['sapi'] === 'cli' && $php['binary'] !== '') {
            $this->note('program: ' . $php['binary']);
        }
        $this->note('php.ini: ' . ($php['ini'] !== '' ? $php['ini'] : 'none loaded'));

        $this->curl = in_array('curl', $php['loaded'], true);
        if ($this->curl) {
            $curl = function_exists('curl_version') ? (array) curl_version() : [];
            $this->ok(sprintf('cURL %s with %s', $curl['version'] ?? '?', ($curl['ssl_version'] ?? '') !== '' ? $curl['ssl_version'] : 'no HTTPS support'));

            return;
        }
        $explanation = PhpExtension::whyMissing('curl', $php);
        $this->fail(array_shift($explanation));
        foreach ($explanation as $line) {
            $this->note($line);
        }
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
        $this->note('If that cannot be done, keep .env and the "var" folder somewhere else: see env-path.example.txt');
        $this->note('and STORAGE_PATH in .env.');
    }

    private function checkSettings(): bool
    {
        $this->section('Settings');
        if ($this->config === null) {
            try {
                $this->config = Config::load($this->projectRoot);
            } catch (ConfigException $error) {
                $this->fail($error->getMessage());
                $this->checkPointerName();

                return false;
            }
        }

        $config = $this->config;
        if ($config->settingsFile !== '') {
            $this->ok(Config::nativePath($config->settingsFile) . ' is valid');
            if ($config->pointerFile !== '') {
                $this->note('found through ' . Config::nativePath($config->pointerFile));
            }
        } else {
            $this->ok('settings are valid');
        }
        $this->note('API ................. ' . $config->apiUrl);
        $this->note('Text comes from ..... auto attendant key ' . $config->smsOption);
        $this->note('Numbers sent as ..... ' . PhoneNumber::format('8452632561', $config->numberFormat) . ' (' . $config->numberFormat . ')');
        $this->note('Numbers spoken as ... ' . PhoneNumber::speak('8452632561', $config->digitStyle) . ' (' . $config->digitStyle . ')');
        $this->note('Limit ............... ' . ($config->limitPerDestination === 0
            ? 'none'
            : sprintf('%d text(s) per destination per %d minutes', $config->limitPerDestination, $config->limitWindowMinutes)));
        $callback = $config->scriptUrl($this->webPath);
        $this->note('Callback URLs ....... ' . ($config->appUrl !== '' ? $callback : 'same host and path as the To-Web URL'));
        $this->note('Log ................. ' . Config::nativePath($config->storagePath . '/logs') . ' (' . $config->logLevel . ')');

        if ($config->unknownKeys !== []) {
            $this->warn('Names in .env that this application does not know (misspelled?): ' . implode(', ', $config->unknownKeys));
        }
        $this->checkUnusedSettingsFile($config);
        $this->checkPointerName();

        // The keys a caller presses are sent to APP_URL. Seen from the web server, a wrong path can be spotted.
        $callbackPath = (string) parse_url($callback, PHP_URL_PATH);
        if ($config->appUrl !== '' && $this->webPath !== '' && strcasecmp($callbackPath, $this->webPath) !== 0) {
            $this->warn(sprintf('APP_URL makes the PBX send the keys of the caller to %s, but this page was reached as %s.', $callbackPath, $this->webPath));
            $this->note('Unless a proxy in front of this server translates the address, the keys will be lost:');
            $this->note('correct APP_URL in .env, or leave it blank.');
        }

        return true;
    }

    /**
     * Explorer hides the ending of known file types, so a file that is named "env-path.txt"
     * there is in fact env-path.txt.txt - which the application does not read.
     */
    private function checkPointerName(): void
    {
        $name = Config::ENV_POINTER;
        $folder = rtrim($this->projectRoot, '/\\') . '/';
        if (is_file($folder . $name) || !is_file($folder . $name . '.txt')) {
            return;
        }
        $this->warn(sprintf('%s is not read: the name has to be %s.', Config::nativePath($folder . $name . '.txt'), $name));
        $this->note(sprintf('Windows Explorer hides the last ".txt". Rename the file:  ren %s.txt %s', $name, $name));
    }

    /** A .env that stayed in the project folder after env-path.txt was pointed somewhere else. */
    private function checkUnusedSettingsFile(Config $config): void
    {
        $local = rtrim($this->projectRoot, '/\\') . '/.env';
        if ($config->pointerFile === '' || !is_file($local)) {
            return;
        }
        $used = realpath($config->settingsFile);
        if ($used !== false && $used === realpath($local)) {
            return;
        }
        $this->warn(sprintf('%s also exists, but it is not used: %s names another place.', Config::nativePath($local), Config::ENV_POINTER));
        $this->note('Delete it, so that the API token is kept in one place only.');
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
        $storage = Config::nativePath($this->config->storagePath);
        if ($failed === []) {
            $this->ok($storage . ' can be written by this PHP');
        } else {
            $this->fail('This PHP cannot write to: ' . Config::nativePath(implode(', ', $failed)) . '. Give the account that runs PHP "Modify" permission on ' . $storage . '.');
        }
    }

    private function describeCertificates(): void
    {
        $this->section('HTTPS certificates');
        $curlIni = (string) ini_get('curl.cainfo');
        $opensslIni = (string) ini_get('openssl.cafile');
        if ($this->config->apiCaFile !== '') {
            $this->ok('checked against NS_API_CA_FILE: ' . Config::nativePath($this->config->apiCaFile));
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
        if (!$this->curl) {
            $this->section('Auto attendant');
            $this->note('Not tested: the API cannot be reached without the cURL extension (see "PHP" above).');

            return;
        }
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

        $this->checkSending($request, $allowSend, $api, $source, $message);
        $this->checkEnding($request, $source);
    }

    /**
     * Who sends the text and from which number; with SendTo, one real text.
     *
     * @param array{domain:string, aa_user:string, prompt:string, option:string} $source
     * @param string|null $message the text to send; null when it could not be read
     */
    private function checkSending(Request $request, bool $allowSend, NetSapiens $api, array $source, ?string $message): void
    {
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
     * What "repeat" in the To-Web URL makes of the end of a call. Shown when it is given.
     *
     * @param array{domain:string, aa_user:string, prompt:string, option:string} $source
     */
    private function checkEnding(Request $request, array $source): void
    {
        if ($request->get('repeat') === '') {
            return;
        }
        $this->section('After the text');
        $repeat = CallPlan::repeat($request);
        if ($repeat === null) {
            $this->warn(sprintf('repeat "%s" is neither yes nor no: the call is hung up, as with repeat=no.', $request->get('repeat')));

            return;
        }
        if (!$repeat) {
            $this->ok('repeat=no: the call is hung up');

            return;
        }
        $this->ok(sprintf(
            'repeat=yes: the caller is sent back to the auto attendant (forward to %s)',
            AutoAttendant::sipAddress($source['aa_user'], $source['domain'])
        ));
        $this->note('The PBX can forward only a call that a user owns, which is the case when the');
        $this->note('auto attendant itself sends the call to this application.');
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
