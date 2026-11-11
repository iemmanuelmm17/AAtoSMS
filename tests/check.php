<?php

declare(strict_types=1);

/** The installation check (php bin/check.php, and index.php?key=...&check=1). */

namespace AAtoSMS\Tests;

use AAtoSMS\Diagnostics;
use AAtoSMS\Request;
use AAtoSMS\Text;

/** Runs the check the way bin/check.php does, against the fake API of a bench. */
function check(Bench $bench, array $values, bool $allowSend = true): array
{
    $api = new \AAtoSMS\NetSapiens($bench->config->apiUrl, $bench->config->apiToken, 5, '', $bench->api);
    $diagnostics = new Diagnostics($bench->folder, $bench->config, $api);
    $report = $diagnostics->run(new Request([], $values), $allowSend);

    return [$report, $diagnostics->problems()];
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
    contains('API ................. https://pbx.example.test/ns-api/v2', $report);
    contains('Text comes from ..... auto attendant key 2', $report);
    contains('Numbers sent as ..... 18452632561 (11digit)', $report);
    contains('Numbers spoken as ... eight, four, five, two, six, three, two, five, six, one (words)', $report);
    contains('Limit ............... 3 text(s) per destination per 60 minutes', $report);
    contains('Callback URLs ....... same host and path as the To-Web URL', $report);
    contains('  [ OK ] ' . $bench->config->storagePath . ' can be written by this PHP', $report);
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

    [$report, $problems] = $run(['SMSOption' => '3']);
    same(1, $problems);
    contains('  [ OK ] Prompt_900001 was read from the API', $report);
    contains('  [FAIL] Key 3 of the auto attendant has no message audio.', $report);

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
    contains('  [FAIL] This PHP cannot write to: ' . $bench->folder . '/blocker/var/logs, ', $report);
    contains('"Modify" permission', $report);
});

test('check: reads the .env file of the project folder', function (): void {
    $folder = temporaryFolder();
    try {
        $missing = new Diagnostics($folder);
        $report = $missing->run(new Request());
        same(1, $missing->problems(), $report);
        contains('  [FAIL] Configuration file not found: ' . $folder . '/.env. Copy .env.example to .env and fill it in.', $report);
        lacks('Storage', $report, 'report stops after the settings');

        file_put_contents($folder . '/.env', "NS_API_URL=https://pbx.example.test/ns-api/v2\r\nNS_API_TOKEN=" . Bench::TOKEN . "\r\nWEBRESPONDER_KEY=short\r\n");
        $invalid = new Diagnostics($folder);
        $report = $invalid->run(new Request());
        same(1, $invalid->problems());
        contains('  [FAIL] Invalid configuration: WEBRESPONDER_KEY is required and must be at least 24 characters long.', $report);

        file_put_contents($folder . '/.env', "NS_API_URL=https://pbx.example.test/ns-api/v2\r\nNS_API_TOKEN=" . Bench::TOKEN . "\r\nWEBRESPONDER_KEY=" . Bench::KEY . "\r\n");
        $valid = new Diagnostics($folder);
        $report = $valid->run(new Request());
        same(0, $valid->problems(), $report);
        contains('  [ OK ] ' . $folder . '/.env is valid', $report);
        contains('  [ OK ] ' . $folder . '/var can be written by this PHP', $report);
        lacks(Bench::TOKEN, $report, 'report');
        lacks(Bench::KEY, $report, 'report');
    } finally {
        removeFolder($folder);
    }
});

test('check in the browser: index.php?key=...&check=1 answers with the report as plain text', function (): void {
    $bench = new Bench();
    $answer = $bench->call(['check' => '1', 'SendTo' => '8452632561']);
    same(200, $answer->status);
    same(['Content-Type' => 'text/plain; charset=UTF-8'], $answer->headers);
    contains('installation check', $answer->body);
    contains('  [ OK ] Prompt_900001 was read from the API', $answer->body);
    contains('  [ OK ] Texts are sent from 18457747000 as user 9000', $answer->body);
    contains('  [WARN] SendTo is ignored here', $answer->body);
    same(0, count($bench->api->requestsOf('POST')), 'the browser check never sends a text');
    same([], $bench->calls(), 'no call is started');
    lacks(Bench::TOKEN, $answer->body, 'report');
    lacks(Bench::KEY, $answer->body, 'report');
    contains('Installation check requested {"ip":"203.0.113.9"}', $bench->log());
    contains("Web server\n  [ OK ] this page was reached as /aatosms/index.php\n", $answer->body);
    contains("\nResult: no problems, 1 warning(s).\n", $answer->body);

    $denied = (new Bench())->call(['check' => '1', 'key' => 'wrong']);
    same(403, $denied->status);
    lacks('installation check', $denied->body, 'answer without the key');
});

test('check in the browser: warns when the address shows that the whole project folder is served', function (): void {
    $exposed = new Bench([], '/aatosms/public/index.php');
    $report = $exposed->call(['check' => '1'])->body;
    contains('  [WARN] This page was reached as /aatosms/public/index.php.', $report);
    contains('Test it: open /aatosms/.env.example in this browser. An error page is the right answer.', $report);
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
    $absolute = new Bench(['APP_URL' => 'https://sms.example.test/sms'], '/aatosms/public/index.php');
    $report = $absolute->call(['check' => '1'])->body;
    contains('  [WARN] This page was reached as /aatosms/public/index.php.', $report);
    contains('Callback URLs ....... https://sms.example.test/sms/index.php', $report);

    // The command line has no address, so the section is left out.
    [$report] = check(new Bench(), []);
    lacks('Web server', $report, 'command line report');
});
