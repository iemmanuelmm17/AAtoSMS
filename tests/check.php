<?php

declare(strict_types=1);

/** The installation check (php bin/check.php, and index.php?key=...&check=1). */

namespace AAtoSMS\Tests;

use AAtoSMS\Config;
use AAtoSMS\Diagnostics;
use AAtoSMS\PhpExtension;
use AAtoSMS\Request;
use AAtoSMS\Text;

/**
 * Runs the check the way bin/check.php does, against the fake API of a bench.
 *
 * @param array<string, mixed>|null $php what the check is told about the running PHP; null = the truth
 */
function check(Bench $bench, array $values, bool $allowSend = true, ?array $php = null): array
{
    $api = new \AAtoSMS\NetSapiens($bench->config->apiUrl, $bench->config->apiToken, 5, '', $bench->api);
    $diagnostics = new Diagnostics($bench->folder, $bench->config, $api, $php);
    $report = $diagnostics->run(new Request([], $values), $allowSend);

    return [$report, $diagnostics->problems()];
}

/**
 * Two PHP installations made of small files, as on a Windows server where the command
 * line finds another PHP than the web server uses:
 *
 *   web/    php.exe, php.ini (switches cURL on), ext/php_curl.dll (thread safe build)
 *   other/  php.exe - the one that runs, a non thread safe build that reads the php.ini of "web"
 *
 * @return array{0:string, 1:array<string, mixed>} the folder, and the facts in the form of PhpExtension::environment()
 */
function installations(): array
{
    $root = temporaryFolder();
    mkdir($root . '/web/ext', 0700, true);
    mkdir($root . '/other', 0700, true);
    file_put_contents($root . '/web/php.exe', '');
    file_put_contents($root . '/other/php.exe', '');
    file_put_contents($root . '/web/ext/php_curl.dll', str_repeat("\x00\x90MZ", 200) . "API20250925,TS,VS17\x00" . str_repeat("\xFF\x00", 200));
    file_put_contents($root . '/web/php.ini', "[PHP]\r\nextension_dir=\"ext\"\r\n;extension=gd\r\nextension=bz2\r\nextension=curl\r\n");

    return [$root, [
        'version' => '8.5.12',
        'sapi' => 'cli',
        'windows' => true,
        'thread_safe' => false,
        'binary' => $root . '/other/php.exe',
        'ini' => $root . '/web/php.ini',
        'ini_files' => [$root . '/web/php.ini'],
        'extension_dir' => $root . '/web/ext',
        'build' => 'API20250925,NTS,VS17',
        'loaded' => ['core', 'date', 'dom', 'json'],
    ]];
}

test('text helpers count characters, not bytes', function (): void {
    same(4, Text::length("caf\u{e9}"));
    same(0, Text::length(''));
    same(3, Text::length("a\nb"));
    same(2, Text::length("\xFF\xFE"), 'not UTF-8: bytes');
    same('short', Text::shorten('short', 10));
    same('one two', Text::shorten("  one\r\n two ", 10));
    same('abcdefg...', Text::shorten('abcdefghijk', 10));
    same("\u{e9}\u{e9}...", Text::shorten(str_repeat("\u{e9}", 9), 5), 'never cuts a character in half');
    same('abcdefghij', Text::shorten('abcdefghij', 10));
});

test('check: without call values it covers PHP, settings and storage', function (): void {
    $bench = new Bench();
    [$report, $problems] = check($bench, []);
    same(0, $problems, $report);
    contains('AAtoSMS ' . \AAtoSMS\VERSION . ' installation check', $report);
    contains('  [ OK ] PHP ' . PHP_VERSION, $report);
    contains('  [ OK ] cURL ', $report);
    contains('  [ OK ] settings are valid', $report);
    contains(
        "         API ................. https://pbx.example.test/ns-api/v2\n"
            . "         Access .............. with the key in the To-Web URL (key=...), from any address\n"
            . "         Text comes from ..... auto attendant key 2\n",
        $report
    );
    lacks('Your address', $report, 'command line report');
    contains('Numbers sent as ..... 18452632561 (11digit)', $report);
    contains('Numbers spoken as ... eight, four, five, two, six, three, two, five, six, one (words)', $report);
    contains('Limit ............... 3 text(s) per destination per 60 minutes', $report);
    contains('Callback URLs ....... same host and path as the To-Web URL', $report);
    contains('  [ OK ] ' . Config::nativePath($bench->config->storagePath) . ' can be written by this PHP', $report);
    contains('HTTPS certificates', $report);
    contains('Not tested. Add the values the PBX sends', $report);
    contains("\nResult: no problems found.\n", $report);
    same(0, count($bench->api->requests), 'the API is not contacted');
    same([], glob($bench->config->storagePath . '/*/check-*'), 'probe files are removed');
    lacks(Bench::TOKEN, $report, 'report');
    lacks(Bench::KEY, $report, 'report');
});

test('check: reads the auto attendant and shows the text, without sending anything', function (): void {
    $bench = new Bench();
    [$report, $problems] = check($bench, ['AccountDomain' => '1180.DynaCloud', 'AccountUser' => '9000', 'SMSNumber' => '8457747000']);
    same(0, $problems, $report);
    contains('Auto attendant 9000 in 1180.DynaCloud', $report);
    contains('  [ OK ] Prompt_900001 was read from the API', $report);
    contains('           key 1  to-callqueue 8560', $report);
    contains('           key 2  play-message: "Thank you for calling Example Dental. Our office is at 10..."', $report);
    contains('           key 3  play-message', $report);
    contains('           key 6  tier: "For billing, press 1."', $report);
    contains('           key 0  to-user 7777', $report);
    contains('  [ OK ] Key 2 holds the text to send (117 characters):', $report);
    contains('           Thank you for calling Example Dental. Our office is at 100 Main Street. Reply to this', $report);
    contains('           message to book an appointment.', $report);
    contains('  [ OK ] Texts are sent from 18457747000 as user 9000', $report);
    same(['GET'], array_column($bench->api->requests, 'method'));
    same('https://pbx.example.test/ns-api/v2/domains/1180.DynaCloud/users/9000/autoattendants/Prompt_900001', $bench->api->requests[0]['url']);
});

test('check: option names the key whose message is shown as the text to send', function (): void {
    $values = ['AccountDomain' => '1180.DynaCloud', 'AccountUser' => '9000', 'SMSNumber' => '8457747000'];

    $bench = new Bench();
    [$report, $problems] = check($bench, $values + ['option' => '5']);
    same(0, $problems, $report);
    contains("  [ OK ] Key 5 holds the text to send (16 characters):\n           uploaded message\n", $report);
    lacks('Key 2 holds', $report, 'report');

    $bench = new Bench(['SMS_SCRIPT_OPTION' => '9']);
    [$report, $problems] = check($bench, $values);
    same(0, $problems, $report);
    contains("  [ OK ] Key 9 holds the text to send (24 characters):\n           First part. Second part.\n", $report);

    [$report, $problems] = check($bench, $values + ['Option' => '5', 'SMSOption' => '2']);
    same(0, $problems, $report);
    contains('  [ OK ] Key 5 holds the text to send (16 characters):', $report);

    [$report, $problems] = check($bench, $values + ['SMSOption' => '2']);
    same(0, $problems, $report);
    contains('  [ OK ] Key 2 holds the text to send (117 characters):', $report);
});

test('check: the report says who may use the application - the key, a list of addresses, or both', function (): void {
    [$report, $problems] = check(new Bench(['IP_ALLOW_LIST' => '64.113.255.88, 64.113.255.0/24']), []);
    same(0, $problems, $report);
    contains(
        "         API ................. https://pbx.example.test/ns-api/v2\n"
            . "         Access .............. with the key in the To-Web URL (key=...), from these addresses only:\n"
            . "                                 64.113.255.88\n"
            . "                                 64.113.255.0/24 (64.113.255.0 to 64.113.255.255)\n"
            . "         Text comes from ..... auto attendant key 2\n",
        $report
    );
    contains("\nResult: no problems found.\n", $report);

    [$report, $problems] = check(new Bench(['WEBRESPONDER_KEY' => '', 'IP_ALLOW_LIST' => '2001:db8:1::/48 ; 10.20.30.40']), []);
    same(0, $problems, $report);
    contains(
        "         Access .............. without a key, from these addresses only:\n"
            . "                                 2001:db8:1::/48 (2001:db8:1:: to 2001:db8:1:ffff:ffff:ffff:ffff:ffff)\n"
            . "                                 10.20.30.40\n"
            . "         Text comes from ..... auto attendant key 2\n",
        $report
    );
    contains("\nResult: no problems found.\n", $report);

    // 64.113.255.88/24 is 256 addresses, which may not be what its writer had in mind.
    [$report, $problems] = check(new Bench(['IP_ALLOW_LIST' => '64.113.255.88/24, 10.20.30.40, 10.1.2.3/30']), []);
    same(0, $problems, $report);
    contains(
        "         Access .............. with the key in the To-Web URL (key=...), from these addresses only:\n"
            . "                                 64.113.255.0/24 (64.113.255.0 to 64.113.255.255)\n"
            . "                                 10.20.30.40\n"
            . "                                 10.1.2.0/30 (10.1.2.0 to 10.1.2.3)\n"
            . "         Text comes from ..... auto attendant key 2\n",
        $report
    );
    contains(
        "  [WARN] IP_ALLOW_LIST: 64.113.255.88/24 is read as the block 64.113.255.0/24 (64.113.255.0 to 64.113.255.255).\n"
            . "         For that one address, write 64.113.255.88.\n"
            . "  [WARN] IP_ALLOW_LIST: 10.1.2.3/30 is read as the block 10.1.2.0/30 (10.1.2.0 to 10.1.2.3).\n"
            . "         For that one address, write 10.1.2.3.\n"
            . "Storage\n",
        $report
    );
    contains("\nResult: no problems, 2 warning(s).\n", $report);
});

test('check: SendTo sends one real text, from the command line only', function (): void {
    $values = ['AccountDomain' => '1180.DynaCloud', 'AccountUser' => '9000', 'SMSNumber' => '8457747000', 'SMSUser' => '7003', 'SendTo' => '(845) 263-2561'];

    $bench = new Bench();
    [$report, $problems] = check($bench, $values);
    same(0, $problems, $report);
    contains('  [ OK ] Texts are sent from 18457747000 as user 7003', $report);
    contains('  [ OK ] One real text was sent to 18452632561 (HTTP 200 {"code":202,"message":"Accepted"})', $report);
    same([['type' => 'sms', 'message' => Bench::SCRIPT, 'destination' => '18452632561', 'from-number' => '18457747000']], $bench->api->texts());
    same('https://pbx.example.test/ns-api/v2/domains/1180.DynaCloud/users/7003/messages', $bench->api->requestsOf('POST')[0]['url']);

    $web = new Bench();
    [$report, $problems] = check($web, $values, false);
    same(0, $problems);
    contains('  [WARN] SendTo is ignored here', $report);
    contains("\nResult: no problems, 1 warning(s).\n", $report);
    same(0, count($web->api->requestsOf('POST')), 'nothing is sent');
});

test('check: repeat in the To-Web URL is explained when it is given', function (): void {
    $values = ['AccountDomain' => '1180.DynaCloud', 'AccountUser' => '9000', 'SMSNumber' => '8457747000'];
    $run = static function (array $more) use ($values): array {
        $bench = new Bench();
        [$report, $problems] = check($bench, $more + $values);
        same(0, count($bench->api->requestsOf('POST')), 'nothing is sent');

        return [$report, $problems];
    };

    [$report, $problems] = $run(['repeat' => 'yes']);
    same(0, $problems, $report);
    contains(
        "  [ OK ] Texts are sent from 18457747000 as user 9000\n"
        . "After the text\n"
        . "  [ OK ] repeat=yes: the caller is sent back to the auto attendant (forward to sip:9000@1180.DynaCloud)\n"
        . "         The PBX can forward only a call that a user owns, which is the case when the\n"
        . "         auto attendant itself sends the call to this application.\n"
        . "\nResult: no problems found.\n",
        $report
    );

    [$report, $problems] = $run(['repeat' => 'Yes', 'AccountUser' => '7777', 'AAUser' => '8002', 'AAPrompt' => 'Prompt_800202', 'SMSOption' => '5']);
    same(0, $problems, $report);
    contains('  [ OK ] repeat=yes: the caller is sent back to the auto attendant (forward to sip:8002@1180.DynaCloud)', $report);

    [$report, $problems] = $run(['repeat' => 'yes', 'AccountDomain' => 'Acme-2.example']);
    same(0, $problems, $report);
    contains('  [ OK ] repeat=yes: the caller is sent back to the auto attendant (forward to sip:9000@Acme-2.example)', $report);

    [$report, $problems] = $run(['repeat' => 'no']);
    same(0, $problems, $report);
    contains("After the text\n  [ OK ] repeat=no: the call is hung up\n\nResult: no problems found.\n", $report);

    [$report, $problems] = $run(['repeat' => 'maybe']);
    same(0, $problems, $report);
    contains("After the text\n  [WARN] repeat \"maybe\" is neither yes nor no: the call is hung up, as with repeat=no.\n\nResult: no problems, 1 warning(s).\n", $report);

    foreach ([[], ['repeat' => '']] as $more) {
        [$report, $problems] = $run($more);
        same(0, $problems, $report);
        lacks('After the text', $report, 'report without repeat');
        lacks('repeat', $report, 'report without repeat');
        contains("\nResult: no problems found.\n", $report);
    }

    // Without a number to send from there is no "Sending" part; this part is shown all the same.
    [$report, $problems] = $run(['repeat' => 'yes', 'SMSNumber' => '']);
    same(0, $problems, $report);
    lacks('Texts are sent from', $report);
    contains('  [ OK ] repeat=yes: the caller is sent back to the auto attendant (forward to sip:9000@1180.DynaCloud)', $report);
});

if (function_exists('proc_open')) {
    test('check on the command line: the names of the To-Web URL are accepted, other names are refused', function (): void {
        $folder = temporaryFolder();
        // bin/check.php as its own process, with a settings file of its own.
        $run = static function (string ...$arguments) use ($folder): array {
            $process = proc_open(
                array_merge([PHP_BINARY !== '' ? PHP_BINARY : 'php', dirname(__DIR__) . '/bin/check.php'], $arguments),
                [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
                dirname(__DIR__),
                array_merge(getenv(), ['AATOSMS_ENV_FILE' => $folder . '/.env'])
            );
            ok(is_resource($process), 'bin/check.php was started');
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            return [proc_close($process), str_replace("\r\n", "\n", $output)];
        };

        try {
            file_put_contents($folder . '/.env', implode("\n", [
                'NS_API_URL=https://pbx.example.test/ns-api/v2',
                'NS_API_TOKEN=' . Bench::TOKEN,
                'WEBRESPONDER_KEY=' . Bench::KEY,
                'STORAGE_PATH=' . $folder . '/var',
                '',
            ]));

            // No auto attendant is named, so the API is not asked: only the names are at stake.
            [$status, $output] = $run('repeat=yes', 'SMSNumber=8457747000', 'option=5', 'smsoption=5', 'SMSUSER=7003', 'AAPrompt=Prompt_900001', 'NmsDnis=8457747000', 'AccountDomain=1180.DynaCloud', 'SendTo=');
            same(0, $status, $output);
            contains(' installation check', $output);
            contains('Not tested. Add the values the PBX sends', $output);
            contains("\nResult: no problems found.\n", $output);
            lacks('Not understood', $output, 'output');

            [$status, $output] = $run('Repeat=yes', 'Again=yes');
            same(2, $status, $output);
            contains("Not understood: Again=yes\n", $output);
            contains("  option=<0-9>           auto attendant key whose message is the text (default 2)\n", $output, 'usage');
            contains("  SMSUser=<user>  AAUser=<ext>  AAPrompt=<name>  NmsDnis=<number>  repeat=<yes|no>\n                         same meaning as in the To-Web URL\n", $output, 'usage');
            lacks('installation check', $output, 'nothing is checked');
        } finally {
            removeFolder($folder);
        }
    });
}

test('check: problems are named and counted', function (): void {
    $run = static function (array $values, ?\Closure $prepare = null, array $env = []): array {
        $bench = new Bench($env);
        if ($prepare !== null) {
            $prepare($bench);
        }
        [$report, $problems] = check($bench, $values + ['AccountDomain' => '1180.DynaCloud', 'AccountUser' => '9000']);

        return [$report, $problems, $bench];
    };

    [$report, $problems] = $run([], static function (Bench $bench): void {
        $bench->api->getAnswers = [FakeApi::answer(401, '{"code":401,"message":"Unauthorized"}')];
    });
    same(1, $problems);
    contains('  [FAIL] ns-api GET /domains/1180.DynaCloud/users/9000/autoattendants/Prompt_900001 returned HTTP 401 - {"code":401,"message":"Unauthorized"}', $report);
    contains('         The API rejected NS_API_TOKEN', $report);
    contains("\nResult: 1 problem(s) to fix, 0 warning(s).\n", $report);

    [$report, $problems] = $run(['option' => '3']);
    same(1, $problems);
    contains('  [ OK ] Prompt_900001 was read from the API', $report);
    contains('  [FAIL] Key 3 of the auto attendant has no message audio.', $report);

    [$report, $problems] = $run(['SMSOption' => '3']);
    same(1, $problems);
    contains('  [FAIL] Key 3 of the auto attendant has no message audio.', $report);

    [$report, $problems, $bench] = $run(['option' => '12']);
    same(1, $problems);
    contains('  [FAIL] option "12" is not an auto attendant key (0-9 or *).', $report);
    same(0, count($bench->api->requests), 'the API is not asked');

    [$report, $problems] = $run(['AccountUser' => '90000']);
    same(1, $problems);
    contains('  [FAIL] Cannot build the prompt name for auto attendant extension "90000"', $report);

    [$report, $problems] = $run(['SMSNumber' => '12345']);
    same(1, $problems);
    contains('  [FAIL] SMSNumber "12345" is not a valid 10-digit number.', $report);

    [$report, $problems, $bench] = $run(['SMSNumber' => '8457747000', 'SendTo' => '911']);
    same(1, $problems);
    contains('  [FAIL] SendTo "911" is not a valid 10-digit number.', $report);
    same(0, count($bench->api->requestsOf('POST')));

    [$report, $problems, $bench] = $run(['SMSNumber' => '8457747000', 'SendTo' => '8452632561', 'SMSOption' => '3']);
    same(2, $problems);
    contains('  [FAIL] No test text was sent because the text could not be read (see above).', $report);
    same(0, count($bench->api->requestsOf('POST')));

    [$report, $problems, $bench] = $run(['SMSNumber' => '8457747000', 'SendTo' => '8452632561'], static function (Bench $bench): void {
        $bench->api->postAnswers = [FakeApi::answer(400, '{"code":400,"message":"Invalid from-number"}')];
    });
    same(1, $problems);
    contains('  [FAIL] ns-api POST /domains/1180.DynaCloud/users/9000/messages returned HTTP 400 - {"code":400,"message":"Invalid from-number"}', $report);

    [$report, $problems] = $run([], null, ['SOME_TYPO' => '1']);
    same(0, $problems);
    contains('  [WARN] Names in .env that this application does not know (misspelled?): SOME_TYPO', $report);
});

test('check: a storage folder that cannot be written is a problem', function (): void {
    $bench = new Bench();
    file_put_contents($bench->folder . '/blocker', 'x');
    $config = \AAtoSMS\Config::fromArray(['STORAGE_PATH' => $bench->folder . '/blocker/var', 'NS_API_URL' => 'https://pbx.example.test/ns-api/v2', 'NS_API_TOKEN' => Bench::TOKEN, 'WEBRESPONDER_KEY' => Bench::KEY], $bench->folder);
    $diagnostics = new Diagnostics($bench->folder, $config);
    $report = $diagnostics->run(new Request());
    same(1, $diagnostics->problems(), $report);
    contains('  [FAIL] This PHP cannot write to: ' . Config::nativePath($bench->folder . '/blocker/var/logs') . ', ', $report);
    contains('"Modify" permission', $report);
});

test('check: reads the .env file of the project folder', function (): void {
    $folder = temporaryFolder();
    try {
        $missing = new Diagnostics($folder);
        $report = $missing->run(new Request());
        same(1, $missing->problems(), $report);
        contains(
            '  [FAIL] Configuration file not found: ' . Config::nativePath($folder . '/.env') . '. Copy .env.example to .env and fill it in.'
                . " If .env is kept in another folder, name that folder in env-path.txt (see env-path.example.txt).\n",
            $report
        );
        lacks('Storage', $report, 'report stops after the settings');
        lacks('[WARN]', $report, 'report');

        file_put_contents($folder . '/.env', "NS_API_URL=https://pbx.example.test/ns-api/v2\r\nNS_API_TOKEN=" . Bench::TOKEN . "\r\nWEBRESPONDER_KEY=short\r\n");
        $invalid = new Diagnostics($folder);
        $report = $invalid->run(new Request());
        same(1, $invalid->problems());
        contains('  [FAIL] Invalid configuration: WEBRESPONDER_KEY must be at least 24 characters long.', $report);

        file_put_contents($folder . '/.env', "NS_API_URL=https://pbx.example.test/ns-api/v2\r\nNS_API_TOKEN=" . Bench::TOKEN . "\r\nWEBRESPONDER_KEY=" . Bench::KEY . "\r\n");
        $valid = new Diagnostics($folder);
        $report = $valid->run(new Request());
        same(0, $valid->problems(), $report);
        contains('  [ OK ] ' . Config::nativePath($folder . '/.env') . " is valid\n         API ................. https://pbx.example.test/ns-api/v2\n", $report);
        lacks('found through', $report, 'report without env-path.txt');
        contains('  [ OK ] ' . Config::nativePath($folder . '/var') . ' can be written by this PHP', $report);
        contains('         Log ................. ' . Config::nativePath($folder . '/var/logs') . ' (info)', $report);
        lacks(Bench::TOKEN, $report, 'report');
        lacks(Bench::KEY, $report, 'report');

        // The list of addresses as it is written in the file: on one line, with or without quotes.
        $base = "NS_API_URL=https://pbx.example.test/ns-api/v2\r\nNS_API_TOKEN=" . Bench::TOKEN . "\r\n";
        foreach (['IP_ALLOW_LIST=64.113.255.88, 64.113.255.0/24', 'IP_ALLOW_LIST = "64.113.255.88,64.113.255.0/24"', "IP_ALLOW_LIST='64.113.255.88 64.113.255.0/24'"] as $line) {
            file_put_contents($folder . '/.env', $base . "WEBRESPONDER_KEY=\r\n" . $line . "\r\n");
            $listed = new Diagnostics($folder);
            $report = $listed->run(new Request());
            same(0, $listed->problems(), $report);
            contains(
                "         Access .............. without a key, from these addresses only:\n"
                    . "                                 64.113.255.88\n"
                    . "                                 64.113.255.0/24 (64.113.255.0 to 64.113.255.255)\n",
                $report,
                $line
            );
        }

        file_put_contents($folder . '/.env', $base . "WEBRESPONDER_KEY=\r\nIP_ALLOW_LIST=64.113.255.88, 64.113.255.0/24 # the PBX\r\n");
        $commented = new Diagnostics($folder);
        $report = $commented->run(new Request());
        same(1, $commented->problems(), $report);
        contains('  [FAIL] Invalid configuration: IP_ALLOW_LIST: "#", "the", "PBX" are not an IP address or a block of addresses. Write addresses like', $report);

        file_put_contents($folder . '/.env', $base . "WEBRESPONDER_KEY=\r\nIP_ALLOW_LIST=\r\n");
        $open = new Diagnostics($folder);
        $report = $open->run(new Request());
        same(1, $open->problems(), $report);
        contains('  [FAIL] Invalid configuration: WEBRESPONDER_KEY is required and must be at least 24 characters long. It may only be left blank when IP_ALLOW_LIST is set.', $report);
    } finally {
        removeFolder($folder);
    }
});

/**
 * A project folder without settings, and a folder next to it that holds the .env.
 *
 * @return array{0:string, 1:string, 2:string, 3:string, 4:callable():array{0:string, 1:int, 2:int}} the folder of
 *         the test, the project folder, the other folder, the text of a valid .env, and a function that
 *         runs the check of the command line: report, problems, warnings
 */
function projectForCheck(): array
{
    $base = temporaryFolder();
    $project = $base . '/www/aatosms';
    $private = $base . '/private/aatosms';
    mkdir($project, 0700, true);
    mkdir($private, 0700, true);
    $env = "NS_API_URL=https://pbx.example.test/ns-api/v2\r\nNS_API_TOKEN=" . Bench::TOKEN . "\r\nWEBRESPONDER_KEY=" . Bench::KEY . "\r\n";
    file_put_contents($private . '/.env', $env);
    $run = static function () use ($project): array {
        $check = new Diagnostics($project);
        $report = $check->run(new Request());

        return [$report, $check->problems(), preg_match_all('/^  \[WARN\] /m', $report)];
    };

    return [$base, $project, $private, $env, $run];
}

test('check: names the .env that env-path.txt leads to', function (): void {
    [$base, $project, $private, , $run] = projectForCheck();
    try {
        file_put_contents($project . '/env-path.txt', "# where .env is kept\r\n\"" . Config::nativePath($private) . "\"\r\n");
        [$report, $problems, $warnings] = $run();
        same([0, 0], [$problems, $warnings], $report);
        contains(
            "Settings\n  [ OK ] " . Config::nativePath($private) . Config::nativePath('/.env') . " is valid\n"
                . '         found through ' . Config::nativePath($project . '/env-path.txt') . "\n"
                . "         API ................. https://pbx.example.test/ns-api/v2\n",
            $report
        );
        // What is not in .env stays with the project folder.
        contains('  [ OK ] ' . Config::nativePath($project . '/var') . ' can be written by this PHP', $report);
        contains("\nResult: no problems found.\n", $report);
        lacks(Bench::TOKEN, $report, 'report');
        lacks(Bench::KEY, $report, 'report');
    } finally {
        removeFolder($base);
    }
});

test('check: a .env that stayed in the project folder is pointed out', function (): void {
    [$base, $project, $private, $env, $run] = projectForCheck();
    try {
        file_put_contents($project . '/env-path.txt', Config::nativePath($private) . "\r\n");
        file_put_contents($project . '/.env', $env);
        [$report, $problems, $warnings] = $run();
        same([0, 1], [$problems, $warnings], $report);
        contains(
            '  [WARN] ' . Config::nativePath($project . '/.env') . " also exists, but it is not used: env-path.txt names another place.\n"
                . "         Delete it, so that the API token is kept in one place only.\n",
            $report
        );
        contains("\nResult: no problems, 1 warning(s).\n", $report);

        // env-path.txt that names the project folder itself: that .env is the one in use.
        foreach (['.', Config::nativePath($project), Config::nativePath($project . '/.env'), '../aatosms'] as $same) {
            file_put_contents($project . '/env-path.txt', $same . "\r\n");
            [$report, $problems, $warnings] = $run();
            same([0, 0], [$problems, $warnings], $same . "\n" . $report);
            contains('         found through ' . Config::nativePath($project . '/env-path.txt') . "\n", $report);
        }

        // Without env-path.txt there is nothing to point out either.
        unlink($project . '/env-path.txt');
        [$report, $problems, $warnings] = $run();
        same([0, 0], [$problems, $warnings], $report);
        contains('  [ OK ] ' . Config::nativePath($project . '/.env') . " is valid\n         API ", $report);

        // The tests name the settings file through a variable; that is no reason for the hint.
        putenv('AATOSMS_ENV_FILE=' . $private . '/.env');
        [$report, $problems, $warnings] = $run();
        same([0, 0], [$problems, $warnings], $report);
        contains('  [ OK ] ' . Config::nativePath($private . '/.env') . " is valid\n         API ", $report);
    } finally {
        putenv('AATOSMS_ENV_FILE');
        removeFolder($base);
    }
});

test('check: an env-path.txt that leads nowhere is a problem, also when an old .env is still there', function (): void {
    [$base, $project, $private, $env, $run] = projectForCheck();
    try {
        file_put_contents($project . '/.env', $env);
        $pointer = Config::nativePath($project . '/env-path.txt');

        file_put_contents($project . '/env-path.txt', Config::nativePath($base . '/privat/aatosms') . "\r\n");
        [$report, $problems] = $run();
        same(1, $problems, $report);
        contains('  [FAIL] ' . $pointer . ' names ' . Config::nativePath($base . '/privat/aatosms') . ', which does not exist or cannot be read by this PHP.', $report);
        lacks(' is valid', $report, 'report');
        lacks('Storage', $report, 'report stops after the settings');

        unlink($private . '/.env');
        file_put_contents($project . '/env-path.txt', Config::nativePath($private) . "\r\n");
        [$report, $problems] = $run();
        same(1, $problems, $report);
        contains('  [FAIL] ' . $pointer . ' names the folder ' . Config::nativePath($private) . ', but there is no .env file in it', $report);
    } finally {
        removeFolder($base);
    }
});

test('check: env-path.txt.txt, as Windows Explorer makes it, is pointed out', function (): void {
    [$base, $project, $private, $env, $run] = projectForCheck();
    try {
        $warning = '  [WARN] ' . Config::nativePath($project . '/env-path.txt.txt') . " is not read: the name has to be env-path.txt.\n"
            . "         Windows Explorer hides the last \".txt\". Rename the file:  ren env-path.txt.txt env-path.txt\n";
        file_put_contents($project . '/env-path.txt.txt', Config::nativePath($private) . "\r\n");

        // The .env was moved already: nothing is found.
        [$report, $problems, $warnings] = $run();
        same([1, 1], [$problems, $warnings], $report);
        contains('  [FAIL] Configuration file not found: ' . Config::nativePath($project . '/.env') . '.', $report);
        contains($warning, $report);

        // The old .env is still there: it is the one in use, which is not what was meant.
        file_put_contents($project . '/.env', $env);
        [$report, $problems, $warnings] = $run();
        same([0, 1], [$problems, $warnings], $report);
        contains('  [ OK ] ' . Config::nativePath($project . '/.env') . " is valid\n", $report);
        contains($warning, $report);

        // With the right name next to it, the other file is just a file.
        file_put_contents($project . '/env-path.txt', Config::nativePath($private) . "\r\n");
        unlink($project . '/.env');
        [$report, $problems, $warnings] = $run();
        same([0, 0], [$problems, $warnings], $report);
        lacks('env-path.txt.txt', $report, 'report');
    } finally {
        removeFolder($base);
    }
});

test('check in the browser: index.php?key=...&check=1 answers with the report as plain text', function (): void {
    $bench = new Bench();
    $answer = $bench->call(['check' => '1', 'SendTo' => '8452632561', 'repeat' => 'yes']);
    same(200, $answer->status);
    same(['Content-Type' => 'text/plain; charset=UTF-8'], $answer->headers);
    contains('installation check', $answer->body);
    contains('  [ OK ] Prompt_900001 was read from the API', $answer->body);
    contains('  [ OK ] Texts are sent from 18457747000 as user 9000', $answer->body);
    contains('  [WARN] SendTo is ignored here', $answer->body);
    contains('  [ OK ] repeat=yes: the caller is sent back to the auto attendant (forward to sip:9000@1180.DynaCloud)', $answer->body);
    same(0, count($bench->api->requestsOf('POST')), 'the browser check never sends a text');
    same([], $bench->calls(), 'no call is started');
    lacks(Bench::TOKEN, $answer->body, 'report');
    lacks(Bench::KEY, $answer->body, 'report');
    contains('Installation check requested {"ip":"203.0.113.9"}', $bench->log());
    // The address of the browser: what IP_ALLOW_LIST would be compared with.
    contains("Web server\n  [ OK ] this page was reached as /aatosms/index.php\n         Your address, as this server sees it: 203.0.113.9\nSettings\n", $answer->body);
    contains("\nResult: no problems, 1 warning(s).\n", $answer->body);

    $denied = (new Bench())->call(['check' => '1', 'key' => 'wrong']);
    same(403, $denied->status);
    lacks('installation check', $denied->body, 'answer without the key');

    // A web server that names no address: the line is left out.
    $nameless = new Bench();
    $nameless->ip = '';
    $report = $nameless->call(['check' => '1'])->body;
    contains("Web server\n  [ OK ] this page was reached as /aatosms/index.php\nSettings\n", $report);

    // With a list of addresses the browser has to be on it. The report then shows the list.
    $listed = new Bench(['IP_ALLOW_LIST' => '64.113.255.88, 64.113.255.0/24, 2001:db8::/32']);
    $denied = $listed->call(['check' => '1']);
    same(403, $denied->status);
    lacks('installation check', $denied->body, 'answer to an address that is not on the list');
    $listed->ip = '2001:db8::7';
    $answer = $listed->call(['check' => '1']);
    same(200, $answer->status);
    contains("\n         Your address, as this server sees it: 2001:db8::7\nSettings\n", $answer->body);
    contains(
        "         Access .............. with the key in the To-Web URL (key=...), from these addresses only:\n"
            . "                                 64.113.255.88\n"
            . "                                 64.113.255.0/24 (64.113.255.0 to 64.113.255.255)\n"
            . "                                 2001:db8::/32 (2001:db8:: to 2001:db8:ffff:ffff:ffff:ffff:ffff:ffff)\n",
        $answer->body
    );
    contains("\nResult: no problems found.\n", $answer->body);
});

test('check in the browser: warns when the address shows that the whole project folder is served', function (): void {
    $exposed = new Bench([], '/aatosms/public/index.php');
    $report = $exposed->call(['check' => '1'])->body;
    contains('  [WARN] This page was reached as /aatosms/public/index.php.', $report);
    contains('Test it: open /aatosms/.env.example in this browser. An error page is the right answer.', $report);
    contains(
        "         If the file is shown, make the web server serve only the \"public\" folder (README, \"Web server\").\n"
            . "         If that cannot be done, keep .env and the \"var\" folder somewhere else: see env-path.example.txt\n"
            . "         and STORAGE_PATH in .env.\n"
            . "         Your address, as this server sees it: 203.0.113.9\n"
            . "Settings\n",
        $report
    );
    contains("\nResult: no problems, 1 warning(s).\n", $report);

    $atRoot = new Bench([], '/public/index.php');
    contains('Test it: open /.env.example in this browser.', $atRoot->call(['check' => '1'])->body);

    foreach (['/aatosms/index.php', '/index.php', '/publications/index.php', '/public/tools/index.php'] as $path) {
        $fine = new Bench([], $path);
        $report = $fine->call(['check' => '1'])->body;
        contains('  [ OK ] this page was reached as ' . $path, $report);
        contains("\nResult: no problems found.\n", $report);
    }

    // With APP_URL the callbacks are absolute, but the check still looks at the real address.
    $absolute = new Bench(['APP_URL' => 'https://sms.example.test/aatosms/public'], '/aatosms/public/index.php');
    $report = $absolute->call(['check' => '1'])->body;
    contains('  [WARN] This page was reached as /aatosms/public/index.php.', $report);
    contains('Callback URLs ....... https://sms.example.test/aatosms/public/index.php', $report);
    contains("\nResult: no problems, 1 warning(s).\n", $report);

    // The command line has no address, so the section is left out.
    [$report] = check(new Bench(), []);
    lacks('Web server', $report, 'command line report');
});

test('check in the browser: an APP_URL that points somewhere else is noticed', function (): void {
    $wrong = new Bench(['APP_URL' => 'https://sms.example.test/sms'], '/aatosms/index.php');
    $report = $wrong->call(['check' => '1'])->body;
    contains('Callback URLs ....... https://sms.example.test/sms/index.php', $report);
    contains('  [WARN] APP_URL makes the PBX send the keys of the caller to /sms/index.php, but this page was reached as /aatosms/index.php.', $report);
    contains("\nResult: no problems, 1 warning(s).\n", $report);

    foreach ([
        ['https://sms.example.test/aatosms', '/aatosms/index.php'],
        ['https://sms.example.test/AATOSMS/', '/aatosms/index.php'],
        ['https://sms.example.test', '/index.php'],
        ['https://sms.example.test:8443/a/b', '/a/b/responder.php'],
        ['https://sms.example.test/aatosms/index.php', '/aatosms/index.php'], // the script itself instead of its folder
        ['https://sms.example.test/aatosms/Index.PHP', '/aatosms/index.php'],
    ] as [$appUrl, $path]) {
        $right = new Bench(['APP_URL' => $appUrl], $path);
        $report = $right->call(['check' => '1'])->body;
        lacks('[WARN]', $report, 'report for ' . $appUrl);
        contains("\nResult: no problems found.\n", $report);
    }

    // The address of the script is shown as it is, without a second file name behind it.
    $script = new Bench(['APP_URL' => 'http://192.0.2.10/aatosms/public/index.php'], '/aatosms/public/index.php');
    $report = $script->call(['check' => '1'])->body;
    contains("Callback URLs ....... http://192.0.2.10/aatosms/public/index.php\n", $report);
    lacks('APP_URL makes the PBX', $report, 'report');

    $other = new Bench(['APP_URL' => 'https://sms.example.test/sms/index.php'], '/aatosms/index.php');
    contains(
        '  [WARN] APP_URL makes the PBX send the keys of the caller to /sms/index.php, but this page was reached as /aatosms/index.php.',
        $other->call(['check' => '1'])->body
    );

    // On the command line the address of the web server is not known: nothing to compare.
    [$report] = check(new Bench(['APP_URL' => 'https://sms.example.test/sms']), []);
    contains('Callback URLs ....... https://sms.example.test/sms/index.php', $report);
    lacks('[WARN]', $report, 'command line report');
});

// ---------------------------------------------------------------------------
// A PHP without cURL: the check says why
// ---------------------------------------------------------------------------

test('check: this PHP is described, and cURL is found', function (): void {
    $php = PhpExtension::environment();
    same(PHP_VERSION, $php['version']);
    same(PHP_SAPI, $php['sapi']);
    same(PHP_OS_FAMILY === 'Windows', $php['windows']);
    same((bool) PHP_ZTS, $php['thread_safe']);
    same(PHP_BINARY, $php['binary']);
    ok(in_array('curl', $php['loaded'], true), 'cURL is among the loaded extensions');
    ok(preg_match('/\AAPI[0-9]{8},(NTS|TS)(,[A-Za-z0-9._-]+)*\z/', $php['build']) === 1, 'build of this PHP: ' . $php['build']);
    same((bool) PHP_ZTS, !str_contains($php['build'], ',NTS'), 'build and thread safety agree');
    ok($php['ini'] === '' || $php['ini_files'][0] === $php['ini'], 'the main php.ini comes first');

    [$report] = check(new Bench(), []);
    contains("PHP\n  [ OK ] PHP " . PHP_VERSION . ' (cli, ' . PHP_OS_FAMILY . ', ' . (PHP_ZTS ? 'thread safe' : 'non thread safe') . ")\n         program: " . PHP_BINARY . "\n         php.ini: ", $report);
    contains("\n  [ OK ] cURL " . curl_version()['version'] . ' with ', $report);
});

test('check: cURL is switched on but its file belongs to another PHP - said in so many words', function (): void {
    [$root, $php] = installations();
    try {
        $bench = new Bench();
        [$report, $problems] = check($bench, ['AccountDomain' => '1180.DynaCloud', 'AccountUser' => '9000', 'SMSNumber' => '8457747000', 'SendTo' => '8452632561'], true, $php);
        $web = Config::nativePath($root . '/web');
        $file = $web . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'php_curl.dll';

        same(1, $problems, $report);
        contains(implode("\n", [
            'PHP',
            '  [ OK ] PHP 8.5.12 (cli, ' . PHP_OS_FAMILY . ', non thread safe)',
            '         program: ' . $root . '/other/php.exe',
            '         php.ini: ' . $root . '/web/php.ini',
            '  [FAIL] The cURL extension is not loaded: its file was made for another PHP than the one that runs.',
            '           ' . $file . ' ... API20250925,TS,VS17',
            '           this PHP ' . str_repeat('.', strlen($file) - 5) . ' API20250925,NTS,VS17',
            '         The two have to be equal: the number stands for the PHP version, TS = thread safe, NTS = non thread safe.',
            '         The extension files belong to the PHP in ' . $web . ', not to the one that runs. Use that PHP:',
            '           "' . $web . DIRECTORY_SEPARATOR . 'php.exe" bin' . DIRECTORY_SEPARATOR . 'check.php',
            'Settings',
        ]), $report);

        // What needs the API is left out instead of failing a second time.
        contains("Auto attendant\n         Not tested: the API cannot be reached without the cURL extension (see \"PHP\" above).\n\nResult: 1 problem(s) to fix, 0 warning(s).\n", $report);
        lacks('HTTPS certificates', $report, 'report');
        lacks('Sending', $report, 'report');
        same(0, count($bench->api->requests), 'the API is not contacted, no text is sent');
        contains('  [ OK ] settings are valid', $report);
        contains(' can be written by this PHP', $report);
    } finally {
        removeFolder($root);
    }
});

test('missing extension: a file made for another PHP', function (): void {
    [$root, $php] = installations();
    try {
        $web = Config::nativePath($root . '/web');
        $file = $web . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'php_curl.dll';
        $headline = 'The cURL extension is not loaded: its file was made for another PHP than the one that runs.';
        $why = static fn (array $changes = []): array => PhpExtension::whyMissing('curl', $changes + $php);

        // The program and the "ext" folder in one folder: two downloads were mixed.
        same([
            $headline,
            '  ' . $file . ' ... API20250925,TS,VS17',
            '  this PHP ' . str_repeat('.', strlen($file) - 5) . ' API20250925,NTS,VS17',
            'The two have to be equal: the number stands for the PHP version, TS = thread safe, NTS = non thread safe.',
            'The program and its "ext" folder are both in ' . $web . ', so two PHP downloads are mixed there.',
            'Unpack one complete PHP download into that folder again (and keep php.ini). The Apache module',
            'of PHP needs the "Thread Safe" download; IIS and FastCGI use the "Non Thread Safe" one.',
            'Stop the web server and every running PHP first: Windows does not replace files that are in use.',
        ], $why(['binary' => $root . '/web/php.exe']));
        same($why(['binary' => $root . '/web/php.exe']), $why(['binary' => $root . '/web/php.exe', 'extension_dir' => 'ext']), 'a relative extension_dir starts at the program');
        same($why(['binary' => $root . '/web/php.exe']), $why(['binary' => strtoupper($root . '/web') . '\\PHP.EXE']), 'Windows: capitals and the kind of slash do not matter');

        // Seen from the web server there is no program to name.
        same([
            $headline,
            '  ' . $file . ' ... API20250925,TS,VS17',
            '  this PHP ' . str_repeat('.', strlen($file) - 5) . ' API20250925,NTS,VS17',
            'The two have to be equal: the number stands for the PHP version, TS = thread safe, NTS = non thread safe.',
            'The "ext" folder and the PHP that the web server runs must come from the same PHP download.',
            'Restart the web server after the change.',
        ], $why(['sapi' => 'apache2handler', 'binary' => $root . '/apache/bin/httpd.exe']));

        // A file of another PHP version is the same kind of mismatch.
        same('  this PHP ' . str_repeat('.', strlen($file) - 5) . ' API20240924,TS,VS17', $why(['build' => 'API20240924,TS,VS17', 'thread_safe' => true])[2]);

        // When this PHP cannot name its own build, thread safety alone is compared.
        same([$headline, '  this PHP ' . str_repeat('.', strlen($file) - 5) . ' non thread safe (NTS)'], [$why(['build' => ''])[0], $why(['build' => ''])[2]]);
        same('The cURL extension is not loaded: PHP could not load ' . $file, $why(['build' => '', 'thread_safe' => true])[0]);

        // No other PHP next to the extension files: only extension_dir can be named.
        unlink($root . '/web/php.exe');
        same('"extension_dir" in php.ini names ' . $root . '/web/ext, which is not the "ext" folder of this PHP. Correct it.', $why()[4]);
        same(5, count($why()));

        // Linux names: curl.so, and "php" as the program.
        mkdir($root . '/usr/lib', 0700, true);
        file_put_contents($root . '/usr/lib/curl.so', "\x7FELF..API20240924,NTS\x00..");
        file_put_contents($root . '/usr/php', '');
        file_put_contents($root . '/linux.ini', "extension=curl\n");
        $facts = ['windows' => false, 'binary' => $root . '/opt/php', 'ini' => $root . '/linux.ini', 'ini_files' => [$root . '/linux.ini'], 'extension_dir' => $root . '/usr/lib', 'build' => 'API20250925,NTS'];
        $linux = $why($facts);
        same('  ' . Config::nativePath($root . '/usr/lib') . DIRECTORY_SEPARATOR . 'curl.so ... API20240924,NTS', $linux[1]);
        same('  "' . Config::nativePath($root . '/usr') . DIRECTORY_SEPARATOR . 'php" bin' . DIRECTORY_SEPARATOR . 'check.php', $linux[5]);
        same(6, count($linux));
        $mixed = $why(['binary' => $root . '/usr/php'] + $facts);
        contains('are both in ' . Config::nativePath($root . '/usr') . ', so two PHP downloads are mixed there.', $mixed[4]);
        same(7, count($mixed), 'the advice about files in use is for Windows');
        same($linux, $why(['binary' => strtoupper($root . '/usr') . '/php'] + $facts), 'there, capitals make another folder');
    } finally {
        removeFolder($root);
    }
});

test('missing extension: switched off, no php.ini, no file, or a file that does not load', function (): void {
    [$root, $php] = installations();
    try {
        $ini = $root . '/web/php.ini';
        $file = Config::nativePath($root . '/web/ext') . DIRECTORY_SEPARATOR . 'php_curl.dll';
        $why = static fn (array $changes = []): array => PhpExtension::whyMissing('curl', $changes + $php);
        $web = ['sapi' => 'apache2handler', 'binary' => $root . '/apache/bin/httpd.exe'];

        // The right build, and still not loaded: only PHP itself knows the reason.
        same([
            'The cURL extension is not loaded: PHP could not load ' . $file,
            'PHP names the reason when it starts: run "php -v" and read the warnings above the version.',
        ], $why(['build' => 'API20250925,TS,VS17', 'thread_safe' => true]));
        same([
            'The cURL extension is not loaded: PHP could not load ' . $file,
            'PHP names the reason when it starts: look in the error log of PHP or of the web server.',
            'Often a file that it needs is not found: libssh2.dll, libssl-*.dll and libcrypto-*.dll are in the',
            'folder of PHP, and that folder has to be in the PATH that the web server runs with.',
            'Restart the web server after the change.',
        ], $why(['build' => 'API20250925,TS,VS17', 'thread_safe' => true] + $web));
        file_put_contents($root . '/web/ext/curl.so', 'API20250925,TS');
        same([
            'The cURL extension is not loaded: PHP could not load ' . Config::nativePath($root . '/web/ext') . DIRECTORY_SEPARATOR . 'curl.so',
            'PHP names the reason when it starts: look in the error log of PHP or of the web server.',
            'Restart the web server after the change.',
        ], $why(['build' => 'API20250925,TS', 'thread_safe' => true, 'windows' => false] + $web), 'the advice about DLL files is for Windows');

        // A file without a build mark (not an extension at all) is not called a mismatch.
        file_put_contents($root . '/web/ext/php_curl.dll', 'API420250925,TS,VS17 API2025,TS not-a-build');
        same('', PhpExtension::buildOf($root . '/web/ext/php_curl.dll'));
        same('The cURL extension is not loaded: PHP could not load ' . $file, $why()[0]);

        // Switched on, but extension_dir points to a folder without the file.
        unlink($root . '/web/ext/php_curl.dll');
        same([
            'The cURL extension is not loaded: ' . $ini . ' switches it on, but its file does not exist:',
            '  ' . $file,
            '"extension_dir" in php.ini has to name the "ext" folder of the PHP that runs (now: ' . $root . '/web/ext).',
        ], $why());
        same('"extension_dir" in php.ini has to name the "ext" folder of the PHP that runs (now: not set).', $why(['extension_dir' => ''])[2]);
        same('Restart the web server after the change.', $why($web)[3]);

        // Not switched on: lines that only look like it do not count.
        file_put_contents($ini, ";extension=curl\r\n; extension=curl\r\nextension=curl_tools\r\nzend_extension=curl\r\nextension=mycurl\r\n#extension=curl\r\n");
        same([
            'The cURL extension is not switched on.',
            'In ' . $ini . ' remove the ";" in front of "extension=curl" (or add that line).',
        ], $why());
        same('Restart the web server after the change.', $why($web)[2]);

        // No php.ini at all.
        same([
            'The cURL extension is not loaded: this PHP runs without a php.ini, so no extension is switched on.',
            'In the folder of PHP copy php.ini-production to php.ini. In that file remove the ";" in front of',
            '  extension_dir = "ext"   and   extension=curl',
        ], $why(['ini' => '', 'ini_files' => []]));

        // Not Windows: there it usually is a package.
        same([
            'The cURL extension is not installed or not switched on.',
            'Debian/Ubuntu: install the package php-curl. Elsewhere: add "extension=curl" to php.ini.',
        ], $why(['windows' => false, 'ini' => '', 'ini_files' => []]));
    } finally {
        removeFolder($root);
    }
});

test('missing extension: every way php.ini can switch it on is understood', function (): void {
    [$root, $php] = installations();
    try {
        $ini = $root . '/web/php.ini';
        $headline = 'The cURL extension is not loaded: its file was made for another PHP than the one that runs.';
        foreach ([
            'extension=curl',
            'extension=php_curl.dll',
            '  extension = "curl"  ; needed by AAtoSMS',
            "extension='php_curl.dll'",
            'extension=CURL',
            "extension=bz2\r\nextension=curl\r\nextension=ftp",
            "extension=bz2\rextension=curl\rextension=ftp",
            'extension=' . $root . '/web/ext/php_curl.dll',
            'extension="' . str_replace('/', '\\', $root) . '\\web\\ext\\PHP_CURL.DLL"',
        ] as $line) {
            file_put_contents($ini, "[PHP]\n" . $line . "\n");
            $lines = PhpExtension::whyMissing('curl', $php);
            if (str_contains($line, '\\') && DIRECTORY_SEPARATOR === '/') {
                // A Windows path cannot be opened here; that the line was understood shows in the answer.
                contains('switches it on, but its file does not exist:', $lines[0], $line);
                continue;
            }
            same($headline, $lines[0], $line);
        }

        // Also in an additional .ini file (conf.d), and the main php.ini is still the one that is named otherwise.
        file_put_contents($ini, "[PHP]\n");
        file_put_contents($root . '/20-curl.ini', "extension=curl\n");
        same($headline, PhpExtension::whyMissing('curl', ['ini_files' => [$ini, $root . '/20-curl.ini', $root . '/missing.ini']] + $php)[0]);
        same('The cURL extension is not switched on.', PhpExtension::whyMissing('curl', ['ini_files' => [$ini, $root . '/missing.ini']] + $php)[0]);
    } finally {
        removeFolder($root);
    }
});

test('missing extension: the build of an extension file is read from the file', function (): void {
    $folder = temporaryFolder();
    try {
        $build = static function (string $content) use ($folder): string {
            file_put_contents($folder . '/x.dll', $content);

            return PhpExtension::buildOf($folder . '/x.dll');
        };
        same('API20250925,TS,VS17', $build("MZ\x90\x00" . str_repeat("\x00", 5000) . "API20250925,TS,VS17\x00more"));
        same('API20250925,NTS,VS17', $build("..API20250925,NTS,VS17\x00.."));
        same('API20240924,NTS', $build("\x7FELF\x00API20240924,NTS\x00"));
        same('API20230831,TS,debug,VS16', $build('xAPI20230831,TS,debug,VS16 y'));
        same('API20250925,NTS,VS17', $build("API420250925,TS,VS17\x00API20250925,NTS,VS17\x00"), 'the mark of the Zend engine is another one');
        same('', $build('no mark in here'));
        same('', $build(''));
        same('', PhpExtension::buildOf($folder . '/missing.dll'));
        same('', PhpExtension::buildOf($folder));
    } finally {
        removeFolder($folder);
    }
});
