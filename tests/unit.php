<?php

declare(strict_types=1);

/** Building blocks: settings, phone numbers, auto attendant rules, XML, API client, log, storage. */

namespace AAtoSMS\Tests;

use AAtoSMS\ApiException;
use AAtoSMS\AutoAttendant;
use AAtoSMS\AutoAttendantException;
use AAtoSMS\CallStore;
use AAtoSMS\CallStoreException;
use AAtoSMS\Config;
use AAtoSMS\ConfigException;
use AAtoSMS\Logger;
use AAtoSMS\NetSapiens;
use AAtoSMS\PhoneNumber;
use AAtoSMS\RateLimiter;
use AAtoSMS\Request;
use AAtoSMS\Xml;

const REQUIRED = [
    'NS_API_URL' => 'https://pbx.example.test/ns-api/v2',
    'NS_API_TOKEN' => 'nss_TESTtoken-1234567890',
    'WEBRESPONDER_KEY' => 'test-key-0123456789abcdefghij',
];

// ---------------------------------------------------------------------------
// Prompt name (rule given by the customer)
// ---------------------------------------------------------------------------

test('prompt name: 4-digit extension gets 01', function (): void {
    same('Prompt_800201', AutoAttendant::promptName('8002'));
    same('Prompt_900001', AutoAttendant::promptName('9000'));
    same('Prompt_000101', AutoAttendant::promptName('0001'));
});

test('prompt name: 3-digit extension gets 001', function (): void {
    same('Prompt_123001', AutoAttendant::promptName('123'));
    same('Prompt_900001', AutoAttendant::promptName('900'));
});

test('prompt name: other lengths are refused with advice', function (): void {
    foreach (['', '12', '12345', '90a0', ' 9000', '9000 '] as $extension) {
        throws(AutoAttendantException::class, static fn () => AutoAttendant::promptName($extension), 'AAPrompt=');
    }
});

// ---------------------------------------------------------------------------
// SMS text taken from the auto attendant
// ---------------------------------------------------------------------------

$autoAttendant = json_decode(fixture('autoattendant.json'), true, 32, JSON_THROW_ON_ERROR);

test('script: text-to-speech message on option 2', function () use ($autoAttendant): void {
    same(Bench::SCRIPT, AutoAttendant::script($autoAttendant, '2'));
});

test('script: uploaded audio uses its script field', function () use ($autoAttendant): void {
    same('uploaded message', AutoAttendant::script($autoAttendant, '5'));
});

test('script: star key and special characters are kept as they are', function () use ($autoAttendant): void {
    same("Star \"quoted\" & <tagged> text.\nLine two with caf\u{e9}.", AutoAttendant::script($autoAttendant, '*'));
});

test('script: a tier uses the text of its own greeting', function () use ($autoAttendant): void {
    same('For billing, press 1.', AutoAttendant::script($autoAttendant, '6'));
});

test('script: several audio files are joined in ordinal order', function () use ($autoAttendant): void {
    same('First part. Second part.', AutoAttendant::script($autoAttendant, '9'));
});

test('script: message without audio ("audio": []) is explained', function () use ($autoAttendant): void {
    $error = throws(AutoAttendantException::class, static fn () => AutoAttendant::script($autoAttendant, '3'), 'no message audio');
    contains('play-message', $error->getMessage());
});

test('script: option that is not a message is explained', function () use ($autoAttendant): void {
    $error = throws(AutoAttendantException::class, static fn () => AutoAttendant::script($autoAttendant, '1'), 'no message audio');
    contains('to-callqueue', $error->getMessage());
    contains('press 1 for callqueue 8560', $error->getMessage());
});

test('script: blank script text is explained', function () use ($autoAttendant): void {
    throws(AutoAttendantException::class, static fn () => AutoAttendant::script($autoAttendant, '7'), 'no script text');
});

test('script: blank lines and spaces around the text are dropped', function (): void {
    $withText = static fn (string $text): array => ['auto-attendant' => ['option-2' => ['audio' => ['file-script-text' => $text]]]];
    same('Hello there.', AutoAttendant::script($withText("  \r\n Hello there. \n\n"), '2'));
    same("Line one.\nLine two.", AutoAttendant::script($withText("Line one.\nLine two.\n"), '2'), 'line breaks inside the text stay');
    throws(AutoAttendantException::class, static fn () => AutoAttendant::script($withText(" \n\t "), '2'), 'no script text');
});

test('script: unassigned key and malformed answers are refused', function () use ($autoAttendant): void {
    throws(AutoAttendantException::class, static fn () => AutoAttendant::script($autoAttendant, '8'), 'nothing assigned to key 8');
    throws(AutoAttendantException::class, static fn () => AutoAttendant::script(['code' => 404, 'message' => 'Not found'], '2'), 'no "auto-attendant" section');
    throws(AutoAttendantException::class, static fn () => AutoAttendant::script(['auto-attendant' => ['option-2' => 'x']], '2'), 'nothing assigned');
    throws(AutoAttendantException::class, static fn () => AutoAttendant::script(['auto-attendant' => ['option-2' => ['audio' => 'x']]], '2'), 'no message audio');
    throws(AutoAttendantException::class, static fn () => AutoAttendant::script(['auto-attendant' => ['option-2' => ['audio' => ['file-script-text' => 12]]]], '2'), 'no script text');
});

test('names used in API paths are validated', function (): void {
    ok(AutoAttendant::isValidDomain('1180.DynaCloud'), 'real domain accepted');
    ok(AutoAttendant::isValidUser('9000') && AutoAttendant::isValidUser('sms_sender-1'), 'users accepted');
    ok(AutoAttendant::isValidPrompt('Prompt_800201'), 'prompt accepted');
    foreach (['', '.', '..', '../x', 'a/b', 'a b', 'a?b', 'a#b', 'a%2Fb', str_repeat('a', 65)] as $bad) {
        ok(!AutoAttendant::isValidDomain($bad), 'domain rejected: ' . $bad);
        ok(!AutoAttendant::isValidPrompt($bad), 'prompt rejected: ' . $bad);
        ok(!AutoAttendant::isValidUser($bad), 'user rejected: ' . $bad);
    }
    ok(!AutoAttendant::isValidUser(str_repeat('1', 17)), 'user longer than 16 rejected');
    foreach (['0', '2', '9', '*'] as $key) {
        ok(AutoAttendant::isValidOption($key), 'option accepted: ' . $key);
    }
    foreach (['', '10', '#', 'a', '-1', ' 2'] as $key) {
        ok(!AutoAttendant::isValidOption($key), 'option rejected: ' . $key);
    }
});

// ---------------------------------------------------------------------------
// Phone numbers
// ---------------------------------------------------------------------------

test('phone: accepted spellings of a US number', function (): void {
    foreach (['8452632561', '18452632561', '+18452632561', '+1 (845) 263-2561', '845-263-2561', '845.263.2561', ' 18452632561 ', '1 845 263 2561'] as $spelling) {
        same('8452632561', PhoneNumber::normalize($spelling), $spelling);
    }
    same('8457747000', PhoneNumber::normalize('8457747000'));
});

test('phone: everything else is not a number we can text', function (): void {
    $invalid = [
        '', ' ', '9000', '845263256', '84526325611', '28452632561', '0452632561', '1452632561', '8450632561', '8451632561',
        'Anonymous', 'Restricted', 'unknown', 'sip:8452632561@pbx', '8452632561;ext=5', '845263256a', '+', '()', '*8452632561', '8452632561#',
        '442079460123', '+442079460123', '118452632561',
    ];
    foreach ($invalid as $value) {
        same(null, PhoneNumber::normalize($value), var_export($value, true));
    }
});

test('phone: formats for the API', function (): void {
    same('18452632561', PhoneNumber::format('8452632561', '11digit'));
    same('8452632561', PhoneNumber::format('8452632561', '10digit'));
    same('+18452632561', PhoneNumber::format('8452632561', 'e164'));
});

test('phone: spoken styles', function (): void {
    same('eight, four, five, two, six, three, two, five, six, one', PhoneNumber::speak('8452632561', 'words'));
    same('8, 4, 5, 2, 6, 3, 2, 5, 6, 1', PhoneNumber::speak('8452632561', 'commas'));
    same('8-4-5-2-6-3-2-5-6-1', PhoneNumber::speak('8452632561', 'hyphens'));
    same('8 4 5 2 6 3 2 5 6 1', PhoneNumber::speak('8452632561', 'spaces'));
    same('eight, four, five, seven, seven, four, seven, zero, zero, zero', PhoneNumber::speak('8457747000', 'words'));
    same('nine, zero, nine', PhoneNumber::speak('909'));
    same('', PhoneNumber::speak('', 'words'));
});

// ---------------------------------------------------------------------------
// Settings (.env)
// ---------------------------------------------------------------------------

test('env: parsing rules', function (): void {
    $text = "\xEF\xBB\xBF# comment\r\n\r\nA=1\r\n  B = two words  \r\nC=\"quoted value\"\r\nD='single'\r\nE=a=b=c\r\nF=\r\n#G=off\r\nnot a setting\r\n1BAD=x\r\nH=\"unbalanced\r\nI=value # not a comment\r\nA=last wins\r\n";
    same([
        'A' => 'last wins',
        'B' => 'two words',
        'C' => 'quoted value',
        'D' => 'single',
        'E' => 'a=b=c',
        'F' => '',
        'H' => '"unbalanced',
        'I' => 'value # not a comment',
    ], Config::parseEnv($text));
    same(['A' => '1', 'B' => '2'], Config::parseEnv("A=1\nB=2"), 'unix line ends, no final newline');
    same(['A' => '1', 'B' => '2'], Config::parseEnv("A=1\rB=2\r"), 'old mac line ends');
});

test('env: a file saved as UTF-16 is reported clearly', function (): void {
    throws(ConfigException::class, static fn () => Config::parseEnv("\xFF\xFEA\x00=\x001\x00"), 'UTF-16');
    throws(ConfigException::class, static fn () => Config::parseEnv("A\x00=\x001\x00"), 'UTF-16');
});

test('config: only three values are required, the rest has defaults', function (): void {
    $config = Config::fromArray(REQUIRED, '/srv/aatosms');
    same('https://pbx.example.test/ns-api/v2', $config->apiUrl);
    same(10, $config->apiTimeout);
    same('', $config->apiCaFile);
    same('', $config->defaultDomain);
    same('', $config->appUrl);
    same('2', $config->smsOption);
    same('', $config->smsUser);
    same('11digit', $config->numberFormat);
    same(3, $config->limitPerDestination);
    same(60, $config->limitWindowMinutes);
    same('words', $config->digitStyle);
    same('', $config->ttsVoice);
    same('', $config->ttsLanguage);
    same(6, $config->inputTimeout);
    same(5, $config->interdigitTimeout);
    same(3, $config->maxAttempts);
    same('info', $config->logLevel);
    same(30, $config->logRetentionDays);
    same('', $config->logTimezone);
    same('/srv/aatosms/var', $config->storagePath);
    same(Config::DEFAULT_PROMPTS, $config->prompts);
    same([], $config->unknownKeys);
});

test('config: values are trimmed, normalised and overridable', function (): void {
    $config = Config::fromArray([
        'NS_API_URL' => ' https://pbx.example.test/ns-api/v2/ ',
        'APP_URL' => 'https://sms.example.test/aatosms/',
        'CALLER_ID_DIGIT_STYLE' => 'Commas',
        'SMS_NUMBER_FORMAT' => 'E164',
        'LOG_LEVEL' => 'DEBUG',
        'SMS_SCRIPT_OPTION' => '0',
        'SMS_USER' => '7003',
        'SMS_LIMIT_PER_DESTINATION' => '0',
        'MAX_ATTEMPTS' => '5',
        'TTS_VOICE' => 'en-US_AllisonVoice',
        'TTS_LANGUAGE' => 'en-US',
        'LOG_TIMEZONE' => 'America/New_York',
        'NS_DEFAULT_DOMAIN' => '1180.DynaCloud',
        'PROMPT_SENT' => 'Sent. Bye.',
        'PROMPT_MENU' => '',
        'SOMETHING_ELSE' => '1',
        'CALLER_ID_DIGITSTYLE' => 'words',
    ] + REQUIRED, '/srv/aatosms/');
    same('https://pbx.example.test/ns-api/v2', $config->apiUrl);
    same('https://sms.example.test/aatosms', $config->appUrl);
    same('commas', $config->digitStyle);
    same('e164', $config->numberFormat);
    same('debug', $config->logLevel);
    same('0', $config->smsOption);
    same('7003', $config->smsUser);
    same(0, $config->limitPerDestination);
    same(5, $config->maxAttempts);
    same('en-US_AllisonVoice', $config->ttsVoice);
    same('America/New_York', $config->logTimezone);
    same('1180.DynaCloud', $config->defaultDomain);
    same('Sent. Bye.', $config->prompts['sent']);
    same(Config::DEFAULT_PROMPTS['menu'], $config->prompts['menu'], 'blank prompt falls back to the default');
    same(['SOMETHING_ELSE', 'CALLER_ID_DIGITSTYLE'], $config->unknownKeys, 'misspelled names are reported');
});

test('config: APP_URL may name the folder of the script or the script itself', function (): void {
    $url = static fn (string $appUrl, string $path): string => Config::fromArray(['APP_URL' => $appUrl] + REQUIRED, '/srv/aatosms')->scriptUrl($path);

    // Without APP_URL the PBX is sent back to the path the script was reached at.
    same('/aatosms/index.php', $url('', '/aatosms/index.php'));
    same('/index.php', $url('', ''), 'no web server: the usual name');

    // The folder: the name of the running script is added.
    same('https://sms.example.test/aatosms/index.php', $url('https://sms.example.test/aatosms', '/local/index.php'));
    same('https://sms.example.test/aatosms/responder.php', $url('https://sms.example.test/aatosms/', '/local/responder.php'));
    same('https://sms.example.test/index.php', $url('https://sms.example.test', ''));
    same('https://sms.example.php/index.php', $url('https://sms.example.php', '/index.php'), 'a host name is not a script');
    same('https://sms.example.test/v2.php.old/index.php', $url('https://sms.example.test/v2.php.old', '/index.php'));

    // The script: used as it is, as in the To-Web URL.
    same('http://192.0.2.10/aatosms/public/index.php', $url('http://192.0.2.10/aatosms/public/index.php', '/aatosms/public/index.php'));
    same('https://sms.example.test/sms/Responder.PHP', $url('https://sms.example.test/sms/Responder.PHP', '/local/index.php'));
    same('https://sms.example.test/index.php', $url('https://sms.example.test/index.php', ''));
});

test('config: paths in messages are written the way the operating system writes them', function (): void {
    same(str_replace('/', DIRECTORY_SEPARATOR, 'D:\\www\\aatosms/var/logs'), Config::nativePath('D:\\www\\aatosms/var/logs'));
    same(DIRECTORY_SEPARATOR === '/' ? '/srv/aatosms/.env' : '\\srv\\aatosms\\.env', Config::nativePath('/srv/aatosms/.env'));
    same('', Config::nativePath(''));
});

test('config: the storage folder may be relative or absolute, on Windows too', function (): void {
    $path = static fn (string $value, string $root): string => Config::fromArray(['STORAGE_PATH' => $value] + REQUIRED, $root)->storagePath;
    same('D:\\www\\aatosms/var', $path('var', 'D:\\www\\aatosms'));
    same('c:\\aatosms/data/sms', $path('data/sms', 'c:\\aatosms\\'));
    same('D:/data/aatosms', $path('D:/data/aatosms/', 'D:\\www\\aatosms'));
    same('E:\\private\\aatosms', $path('E:\\private\\aatosms\\', 'D:\\www\\aatosms'));
    same('\\\\fileserver\\share\\aatosms', $path('\\\\fileserver\\share\\aatosms', 'D:\\www\\aatosms'));
    same('/var/lib/aatosms', $path('/var/lib/aatosms', '/srv/aatosms'));
    same('/srv/aatosms/var', $path('', '/srv/aatosms'));
});

test('config: every problem is reported in one message', function (): void {
    $error = throws(ConfigException::class, static fn () => Config::fromArray([
        'NS_API_URL' => 'pbx.example.test',
        'WEBRESPONDER_KEY' => 'short',
        'CALLER_ID_DIGIT_STYLE' => 'sung',
        'SMS_SCRIPT_OPTION' => '10',
        'MAX_ATTEMPTS' => '0',
        'INPUT_TIMEOUT_SECONDS' => 'six',
        'LOG_TIMEZONE' => 'Mars/Phobos',
    ], '/srv/aatosms'));
    foreach (['NS_API_URL', 'NS_API_TOKEN', 'WEBRESPONDER_KEY', 'CALLER_ID_DIGIT_STYLE', 'SMS_SCRIPT_OPTION', 'MAX_ATTEMPTS', 'INPUT_TIMEOUT_SECONDS', 'LOG_TIMEZONE'] as $name) {
        contains($name, $error->getMessage());
    }
    contains('words, commas, hyphens, spaces', $error->getMessage());
});

test('config: individual rules', function (): void {
    $rejects = static function (array $values, string $part): void {
        throws(ConfigException::class, static fn () => Config::fromArray($values + REQUIRED, '/srv/aatosms'), $part);
    };
    $rejects(['NS_API_URL' => 'http://pbx.example.test/ns-api/v2'], 'https://');
    $rejects(['NS_API_URL' => 'ftp://pbx.example.test/'], 'NS_API_URL');
    $rejects(['NS_API_URL' => 'https://user:pw@pbx.example.test/ns-api/v2'], 'NS_API_URL');
    $rejects(['NS_API_URL' => 'https://pbx.example.test/ns-api/v2?x=1'], 'NS_API_URL');
    $rejects(['NS_API_TOKEN' => 'has space'], 'NS_API_TOKEN');
    $rejects(['WEBRESPONDER_KEY' => str_repeat('a', 23)], 'at least 24');
    $rejects(['WEBRESPONDER_KEY' => str_repeat('a', 23) . '&'], 'WEBRESPONDER_KEY may only contain');
    $rejects(['WEBRESPONDER_KEY' => REQUIRED['NS_API_TOKEN']], 'must not be the same');
    $rejects(['APP_URL' => 'sms.example.test/aatosms'], 'APP_URL');
    $rejects(['NS_DEFAULT_DOMAIN' => 'bad domain'], 'NS_DEFAULT_DOMAIN');
    $rejects(['SMS_USER' => '../x'], 'SMS_USER');
    $rejects(['SMS_NUMBER_FORMAT' => 'international'], 'SMS_NUMBER_FORMAT');
    $rejects(['NS_API_TIMEOUT' => '1'], 'NS_API_TIMEOUT');
    $rejects(['NS_API_TIMEOUT' => '26'], 'NS_API_TIMEOUT');
    $rejects(['SMS_LIMIT_PER_DESTINATION' => '-1'], 'SMS_LIMIT_PER_DESTINATION');
    $rejects(['LOG_LEVEL' => 'verbose'], 'LOG_LEVEL');
    $rejects(['TTS_VOICE' => 'a"b'], 'TTS_VOICE');
    $rejects(['NS_API_CA_FILE' => '/no/such/cacert.pem'], 'NS_API_CA_FILE');
    $rejects(['PROMPT_SENT' => str_repeat('x', 1001)], 'PROMPT_SENT');

    // Plain http is tolerated for a test API on this machine only.
    same('http://127.0.0.1:8080/ns-api/v2', Config::fromArray(['NS_API_URL' => 'http://127.0.0.1:8080/ns-api/v2'] + REQUIRED, '/x')->apiUrl);
    same('http://localhost/ns-api/v2', Config::fromArray(['NS_API_URL' => 'http://localhost/ns-api/v2'] + REQUIRED, '/x')->apiUrl);
});

test('config: secrets do not show up in dumps', function (): void {
    $dump = print_r(Config::fromArray(REQUIRED, '/srv/aatosms'), true);
    lacks(REQUIRED['NS_API_TOKEN'], $dump, 'dump');
    lacks(REQUIRED['WEBRESPONDER_KEY'], $dump, 'dump');
    contains('[redacted]', $dump);
});

test('config: loading from a folder', function (): void {
    $folder = temporaryFolder();
    try {
        throws(ConfigException::class, static fn () => Config::load($folder), 'Copy .env.example to .env');
        $lines = [];
        foreach (REQUIRED as $name => $value) {
            $lines[] = $name . '=' . $value;
        }
        file_put_contents($folder . '/.env', "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\nCALLER_ID_DIGIT_STYLE=commas\r\n");
        $config = Config::load($folder);
        same('commas', $config->digitStyle);
        same($folder . '/var', $config->storagePath);
    } finally {
        removeFolder($folder);
    }
});

test('.env.example: valid once the three required values are filled in, and equal to the built-in defaults', function (): void {
    $example = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/.env.example'));
    $values = Config::parseEnv($example);
    same('', $values['NS_API_TOKEN'], 'no token in the example');
    same('', $values['WEBRESPONDER_KEY'], 'no key in the example');
    throws(ConfigException::class, static fn () => Config::fromArray($values, '/srv/aatosms'), 'NS_API_TOKEN is required');

    $fromExample = Config::fromArray(['NS_API_URL' => REQUIRED['NS_API_URL'], 'NS_API_TOKEN' => REQUIRED['NS_API_TOKEN'], 'WEBRESPONDER_KEY' => REQUIRED['WEBRESPONDER_KEY']] + $values, '/srv/aatosms');
    same([], $fromExample->unknownKeys, 'every name in the example is a real setting');
    same(get_object_vars(Config::fromArray(REQUIRED, '/srv/aatosms')), get_object_vars($fromExample), 'example values equal the defaults');

    // Every setting the application reads is documented in the example ...
    $reflection = new \ReflectionClassConstant(Config::class, 'KNOWN_KEYS');
    foreach ($reflection->getValue() as $name) {
        ok(array_key_exists($name, $values), $name . ' is documented in .env.example');
    }
    // ... and the commented prompts are exactly the built-in ones.
    foreach (Config::DEFAULT_PROMPTS as $name => $text) {
        contains("\n#PROMPT_" . strtoupper($name) . '=' . $text . "\n", $example, '.env.example');
    }
    same(count(Config::DEFAULT_PROMPTS), preg_match_all('/^#PROMPT_[A-Z_]+=/m', $example), 'no stale prompt in the example');
});

// ---------------------------------------------------------------------------
// Request parameters
// ---------------------------------------------------------------------------

test('request: POST body first, then URL; names are case-insensitive; blanks fall through', function (): void {
    $request = new Request(
        ['NmsAni' => '8452632561', 'SMSNumber' => '', 'Digits' => ' 1 ', 'list' => ['1']],
        ['smsnumber' => '8457747000', 'NMSANI' => '1111111111', 'key' => 'k', 'Long' => str_repeat('x', 513), 'ctl' => "a\r\nb"]
    );
    same('8452632561', $request->get('nmsani'));
    same('8452632561', $request->get('NmsAni'));
    same('8457747000', $request->get('SMSNumber'), 'blank in the body falls back to the URL');
    same('1', $request->get('Digits'), 'trimmed');
    same('', $request->get('list'), 'arrays are ignored');
    same('', $request->get('Long'), 'oversized values are ignored');
    same('a b', $request->get('ctl'), 'control characters are removed');
    same('', $request->get('missing'));
    same('[redacted]', $request->snapshot()['key']);
});

// ---------------------------------------------------------------------------
// XML
// ---------------------------------------------------------------------------

test('xml: text and attributes are escaped', function (): void {
    $document = Xml::document(
        'ab12cd34',
        Xml::gather('https://h.test/i.php?a=1&b="2"', 1, 6, 5, Xml::say('Tom & Jerry <b> "q" \'s\'', 'vo"ice', 'en-US')),
        Xml::hangup()
    );
    same(
        '<?xml version="1.0" encoding="UTF-8"?><Response><!-- AAtoSMS ' . \AAtoSMS\VERSION . ' ref=ab12cd34 -->'
        . '<Gather input="dtmf" numDigits="1" timeout="6" digitEndTimeout="5" action="https://h.test/i.php?a=1&amp;b=&quot;2&quot;">'
        . '<Say voice="vo&quot;ice" language="en-US">Tom &amp; Jerry &lt;b&gt; &quot;q&quot; &apos;s&apos;</Say></Gather><Hangup/></Response>',
        $document
    );
    same('Tom & Jerry <b> "q" \'s\'', spoken($document));
    same('https://h.test/i.php?a=1&b="2"', gather($document)['action']);
});

test('xml: invalid bytes and control characters cannot break the document', function (): void {
    $document = Xml::document('--x-->', Xml::say("bad \xFF byte, bell \x07, ok"));
    same("bad \u{FFFD} byte, bell \u{FFFD}, ok", spoken($document), 'the rest of the text is kept');
    lacks('--x', $document, 'comment');
    same("caf\u{e9} \u{1F600}", spoken(Xml::document('', Xml::say("caf\u{e9} \u{1F600}"))), 'valid UTF-8 is untouched');
});

// ---------------------------------------------------------------------------
// API client
// ---------------------------------------------------------------------------

test('api: reading an auto attendant sends exactly the documented request', function (): void {
    $api = new FakeApi();
    $client = new NetSapiens('https://nms1.nyc.birns.net/ns-api/v2/', 'TOKEN-123', 7, '', $api);
    $data = $client->autoAttendant('1180.DynaCloud', '8002', 'Prompt_800201');
    same('Example AA', $data['attendant-name']);
    same(1, count($api->requests));
    same('GET', $api->requests[0]['method']);
    same('https://nms1.nyc.birns.net/ns-api/v2/domains/1180.DynaCloud/users/8002/autoattendants/Prompt_800201', $api->requests[0]['url']);
    same(['Accept: application/json', 'Authorization: Bearer TOKEN-123'], $api->requests[0]['headers']);
    same(null, $api->requests[0]['body']);
    same(['timeout' => 7, 'connect_timeout' => 5, 'ca_file' => ''], $api->requests[0]['options']);
});

test('api: sending a text sends exactly the documented request', function (): void {
    $api = new FakeApi();
    $client = new NetSapiens('https://nms1.nyc.birns.net/ns-api/v2', 'TOKEN-123', 3, 'C:/php/cacert.pem', $api);
    $result = $client->sendSms('1180.DynaCloud', '7003', '18452632561', '18457747000', "Hi \"there\" & <you>\nLine 2 caf\u{e9} / done");
    same(['status' => 200, 'body' => '{"code":202,"message":"Accepted"}'], $result);
    same('POST', $api->requests[0]['method']);
    same('https://nms1.nyc.birns.net/ns-api/v2/domains/1180.DynaCloud/users/7003/messages', $api->requests[0]['url']);
    same(['Accept: application/json', 'Authorization: Bearer TOKEN-123', 'Content-Type: application/json'], $api->requests[0]['headers']);
    same('{"type":"sms","message":"Hi \"there\" & <you>\nLine 2 caf' . "\u{e9}" . ' / done","destination":"18452632561","from-number":"18457747000"}', $api->requests[0]['body']);
    same(['timeout' => 3, 'connect_timeout' => 3, 'ca_file' => 'C:/php/cacert.pem'], $api->requests[0]['options']);
});

test('api: path parts are encoded', function (): void {
    $api = new FakeApi();
    (new NetSapiens('https://pbx.example.test/ns-api/v2', 't', 5, '', $api))->autoAttendant('a b', 'c/d', 'e?f');
    same('https://pbx.example.test/ns-api/v2/domains/a%20b/users/c%2Fd/autoattendants/e%3Ff', $api->requests[0]['url']);
});

test('api: a read is tried once more after a temporary failure', function (): void {
    foreach ([FakeApi::answer(500, 'oops'), FakeApi::answer(503), FakeApi::answer(429), FakeApi::answer(0, '', 'cURL error 7: Failed to connect')] as $failure) {
        $api = new FakeApi();
        $api->getAnswers = [$failure];
        $data = (new NetSapiens('https://pbx.example.test/ns-api/v2', 't', 5, '', $api))->autoAttendant('d', '9000', 'Prompt_900001');
        same('9000', $data['user']);
        same(2, count($api->requests), 'two attempts');
    }
});

test('api: a read is not repeated after a definite answer, and gives up after two tries', function (): void {
    foreach ([401, 403, 404, 400] as $status) {
        $api = new FakeApi();
        $api->getAnswers = [FakeApi::answer($status, '{"code":' . $status . ',"message":"No"}')];
        $error = throws(ApiException::class, static fn () => (new NetSapiens('https://pbx.example.test/ns-api/v2', 't', 5, '', $api))->autoAttendant('d', '9000', 'Prompt_900001'), 'returned HTTP ' . $status);
        same($status, $error->status);
        same('{"code":' . $status . ',"message":"No"}', $error->responseBody);
        same(1, count($api->requests), 'one attempt for HTTP ' . $status);
    }
    $api = new FakeApi();
    $api->getAnswers = [FakeApi::answer(502), FakeApi::answer(502)];
    throws(ApiException::class, static fn () => (new NetSapiens('https://pbx.example.test/ns-api/v2', 't', 5, '', $api))->autoAttendant('d', '9000', 'Prompt_900001'), 'HTTP 502');
    same(2, count($api->requests));
});

test('api: a text is never sent twice by the client itself', function (): void {
    foreach ([FakeApi::answer(500, 'oops'), FakeApi::answer(0, '', 'cURL error 28: Operation timed out'), FakeApi::answer(429)] as $failure) {
        $api = new FakeApi();
        $api->postAnswers = [$failure];
        throws(ApiException::class, static fn () => (new NetSapiens('https://pbx.example.test/ns-api/v2', 't', 5, '', $api))->sendSms('d', 'u', '1', '2', 'm'));
        same(1, count($api->requests), 'exactly one POST');
    }
});

test('api: any 2xx answer to a text counts as accepted, with or without a body', function (): void {
    foreach ([FakeApi::answer(200, ''), FakeApi::answer(202, '{"code":202,"message":"Accepted"}'), FakeApi::answer(201, 'OK')] as $answer) {
        $api = new FakeApi();
        $api->postAnswers = [$answer];
        same($answer['status'], (new NetSapiens('https://pbx.example.test/ns-api/v2', 't', 5, '', $api))->sendSms('d', 'u', '1', '2', 'm')['status']);
    }
});

test('api: a redirect is an error, not an answer', function (): void {
    $client = static fn (FakeApi $api): NetSapiens => new NetSapiens('https://pbx.example.test/ns-api/v2', 't', 5, '', $api);
    $api = new FakeApi();
    $api->getAnswers = [FakeApi::answer(302, '')];
    same(302, throws(ApiException::class, static fn () => $client($api)->autoAttendant('d', '9000', 'p'), 'returned HTTP 302')->status);
    same(1, count($api->requests), 'not tried again');

    $api = new FakeApi();
    $api->postAnswers = [FakeApi::answer(301, '')];
    throws(ApiException::class, static fn () => $client($api)->sendSms('d', 'u', '1', '2', 'm'), 'returned HTTP 301');
});

test('api: unusable answers to a read are refused', function (): void {
    foreach (['', 'null', '[]', '"text"', '<html>login</html>', '[1,2]', '{}'] as $body) {
        $api = new FakeApi();
        $api->getAnswers = [FakeApi::answer(200, $body)];
        throws(ApiException::class, static fn () => (new NetSapiens('https://pbx.example.test/ns-api/v2', 't', 5, '', $api))->autoAttendant('d', '9000', 'p'), 'did not return an auto attendant');
    }
    $api = new FakeApi();
    $api->getAnswers = [FakeApi::answer(200, '[' . fixture('autoattendant.json') . ']')];
    same('9000', (new NetSapiens('https://pbx.example.test/ns-api/v2', 't', 5, '', $api))->autoAttendant('d', '9000', 'p')['user'], 'an object wrapped in a one-element list is accepted');
});

test('api: errors come with a pointer to the usual cause', function (): void {
    same('', (new ApiException('x', 400))->hint());
    contains('NS_API_TOKEN', (new ApiException('x', 401))->hint());
    contains('not allowed', (new ApiException('x', 403))->hint());
    contains('prompt name', (new ApiException('x', 404))->hint());
    contains('internal error', (new ApiException('x', 500))->hint());
    contains('NS_API_CA_FILE', (new ApiException('ns-api GET /x failed: cURL error 60: SSL certificate problem: unable to get local issuer certificate'))->hint());
    contains('NS_API_CA_FILE', (new ApiException('ns-api GET /x failed: cURL error 77: error setting certificate file'))->hint());
    contains('host name in NS_API_URL', (new ApiException("ns-api GET /x failed: cURL error 60: SSL: no alternative certificate subject name matches target host name 'pbx.example.test'"))->hint());
    contains('host name in NS_API_URL', (new ApiException('ns-api GET /x failed: cURL error 51: SSL: certificate subject name does not match'))->hint());
    contains('has expired', (new ApiException('ns-api GET /x failed: cURL error 60: SSL certificate problem: certificate has expired'))->hint());
    contains('DNS', (new ApiException('ns-api GET /x failed: cURL error 6: Could not resolve host'))->hint());
    contains('firewall', (new ApiException('ns-api GET /x failed: cURL error 7: Failed to connect'))->hint());
    contains('firewall', (new ApiException('ns-api GET /x failed: cURL error 28: Operation timed out'))->hint());
    same('', (new ApiException('ns-api GET /x failed: cURL error 52: Empty reply'))->hint());
});

test('api: long or multi-line error bodies are shortened for the log', function (): void {
    $api = new FakeApi();
    $api->postAnswers = [FakeApi::answer(400, "{\n  \"code\": 400,\n  \"message\": \"" . str_repeat('x', 400) . "\"\n}")];
    $error = throws(ApiException::class, static fn () => (new NetSapiens('https://pbx.example.test/ns-api/v2', 't', 5, '', $api))->sendSms('d', 'u', '1', '2', 'm'));
    same(303, strlen($error->responseBody));
    lacks("\n", $error->responseBody, 'excerpt');
});

// ---------------------------------------------------------------------------
// Log
// ---------------------------------------------------------------------------

test('log: one line per event in a daily file, filtered by level', function (): void {
    $folder = temporaryFolder();
    try {
        $logger = new Logger($folder . '/logs', 'warning', new \DateTimeZone('America/New_York'));
        $logger->setCallRef('ab12cd34');
        $logger->debug('hidden debug');
        $logger->info('hidden info');
        $logger->warning("Line\r\nbreak", ['n' => 1, 'url' => 'https://x.test/a/b', 'text' => "caf\u{e9}"]);
        $logger->error('Broken');
        $files = glob($folder . '/logs/*.log');
        same(1, count($files));
        ok(preg_match('/aatosms-\d{4}-\d{2}-\d{2}\.log\z/', $files[0]) === 1, 'daily file name');
        $lines = explode("\n", trim((string) file_get_contents($files[0])));
        same(2, count($lines));
        ok(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-0[45]:00 WARNING \[ab12cd34\] Line break \{"n":1,"url":"https:\/\/x\.test\/a\/b","text":"caf\x{e9}"\}\z/u', $lines[0]) === 1, 'warning line format: ' . $lines[0]);
        ok(preg_match('/ ERROR   \[ab12cd34\] Broken\z/', $lines[1]) === 1, 'error line format: ' . $lines[1]);

        // The call reference is the only free text in front of the message: letters and digits only.
        $logger->setCallRef("x] fake [9\n");
        $logger->error('Third');
        $logger->setCallRef('');
        $logger->error('Fourth');
        $lines = explode("\n", trim((string) file_get_contents($files[0])));
        ok(preg_match('/ ERROR   \[xfake9\] Third\z/', $lines[2]) === 1, 'cleaned reference: ' . $lines[2]);
        ok(preg_match('/ ERROR   \[-\] Fourth\z/', $lines[3]) === 1, 'no reference: ' . $lines[3]);
    } finally {
        removeFolder($folder);
    }
});

test('log: secrets never reach the file', function (): void {
    $folder = temporaryFolder();
    try {
        $logger = new Logger($folder, 'debug');
        $logger->addSecret('nss_SECRETtoken');
        $logger->addSecret('responder-key-abcdefghijklmnop');
        $logger->info('token nss_SECRETtoken in text', [
            'header' => 'Authorization: Bearer some.other.token',
            'url' => 'https://h.test/index.php?key=responder-key-abcdefghijklmnop&SMSNumber=8457747000',
            'other' => 'https://h.test/index.php?key=another-key&x=1',
            'xml' => '<Gather action="/aatosms/index.php?call=0123456789abcdef0123456789abcdef.3"><Say>Hi</Say></Gather>',
        ]);
        $log = (string) file_get_contents(glob($folder . '/*.log')[0]);
        foreach (['nss_SECRETtoken', 'responder-key-abcdefghijklmnop', 'some.other.token', 'another-key', '0123456789abcdef0123456789abcdef'] as $secret) {
            lacks($secret, $log, 'log');
        }
        contains('SMSNumber=8457747000', $log);
        contains('x=1', $log);
        contains('call=[redacted]\\"><Say>Hi</Say>', $log);
    } finally {
        removeFolder($folder);
    }
});

test('log: an unwritable folder falls back to the PHP error log instead of failing', function (): void {
    $folder = temporaryFolder();
    $previous = ini_get('error_log');
    try {
        file_put_contents($folder . '/blocker', 'a file where the log folder should be');
        ini_set('error_log', $folder . '/php-error.log');
        $logger = new Logger($folder . '/blocker/logs', 'info');
        $logger->addSecret('nss_SECRETtoken');
        $logger->error('First problem nss_SECRETtoken');
        $logger->error('Second problem');
        $fallback = (string) file_get_contents($folder . '/php-error.log');
        contains('cannot write to the log folder', $fallback);
        contains('First problem [redacted]', $fallback);
        contains('Second problem', $fallback);
        same(1, substr_count($fallback, 'cannot write to the log folder'), 'the folder problem is reported once');
    } finally {
        ini_set('error_log', (string) $previous);
        removeFolder($folder);
    }
});

test('log: old daily files are deleted, other files are left alone', function (): void {
    $folder = temporaryFolder();
    try {
        $now = 1_800_000_000;
        foreach (['aatosms-2026-01-01.log' => 40, 'aatosms-2026-02-01.log' => 31, 'aatosms-2026-03-01.log' => 29, 'notes.txt' => 400, 'aatosms-old.log' => 400] as $name => $ageDays) {
            file_put_contents($folder . '/' . $name, 'x');
            touch($folder . '/' . $name, $now - $ageDays * 86400);
        }
        $logger = new Logger($folder);
        same(0, $logger->prune(0, $now), 'retention 0 keeps everything');
        same(2, $logger->prune(30, $now));
        $left = array_values(array_diff(scandir($folder), ['.', '..']));
        sort($left);
        same(['aatosms-2026-03-01.log', 'aatosms-old.log', 'notes.txt'], $left);
    } finally {
        removeFolder($folder);
    }
});

// ---------------------------------------------------------------------------
// Call state
// ---------------------------------------------------------------------------

test('call store: create, reopen, change, reopen', function (): void {
    $folder = temporaryFolder();
    try {
        $store = new CallStore($folder . '/calls');
        $session = $store->create(['ref' => 'ab12cd34', 'stage' => 'menu', 'step' => 1, 'message' => "caf\u{e9} \"x\""]);
        ok(CallStore::isValidId($session->id), 'id is 32 hex characters');
        $id = $session->id;
        $session->close();
        $session->close();

        $again = $store->open($id);
        same(['ref' => 'ab12cd34', 'stage' => 'menu', 'step' => 1, 'message' => "caf\u{e9} \"x\""], $again->state);
        $again->state['step'] = 2;
        $again->state['responses'] = [1 => '<xml/>'];
        $again->save();
        $again->state['message'] = 'shorter';
        $again->save();
        $again->close();
        throws(CallStoreException::class, static fn () => $again->save(), 'already closed');

        $third = $store->open($id);
        same(2, $third->state['step']);
        same('shorter', $third->state['message'], 'a shorter state fully replaces a longer one');
        same([1 => '<xml/>'], $third->state['responses'], 'step numbers survive as integer keys');
        $third->close();
        same(1, count(glob($folder . '/calls/*.json')));
    } finally {
        removeFolder($folder);
    }
});

test('call store: unknown, malformed and damaged ids', function (): void {
    $folder = temporaryFolder();
    try {
        $store = new CallStore($folder . '/calls');
        same(null, $store->open(str_repeat('a', 32)), 'folder does not exist yet');
        $store->create(['ref' => 'r', 'stage' => 'menu', 'step' => 1])->close();
        same(null, $store->open(str_repeat('a', 32)));
        foreach (['', 'abc', str_repeat('g', 32), str_repeat('A', 32), '../' . str_repeat('a', 29), str_repeat('a', 33)] as $id) {
            same(null, $store->open($id), 'rejected id ' . $id);
        }
        file_put_contents($folder . '/calls/' . str_repeat('b', 32) . '.json', '{"half":');
        throws(CallStoreException::class, static fn () => $store->open(str_repeat('b', 32)), 'damaged');
        file_put_contents($folder . '/calls/' . str_repeat('c', 32) . '.json', '{"ref":"r"}');
        throws(CallStoreException::class, static fn () => $store->open(str_repeat('c', 32)), 'damaged');

        // An id is never used as a path: a valid state file outside the folder stays out of reach.
        file_put_contents($folder . '/outside.json', '{"ref":"r","stage":"menu","step":1}');
        mkdir($folder . '/calls/sub');
        file_put_contents($folder . '/calls/sub/inside.json', '{"ref":"r","stage":"menu","step":1}');
        foreach (['../outside', 'sub/inside', 'sub\\inside'] as $id) {
            same(null, $store->open($id), 'rejected id ' . $id);
        }
    } finally {
        removeFolder($folder);
    }
});

test('call store: old calls are deleted', function (): void {
    $folder = temporaryFolder();
    try {
        $store = new CallStore($folder);
        same(0, (new CallStore($folder . '/missing'))->prune(3600));
        $now = 1_800_000_000;
        foreach ([str_repeat('a', 32) . '.json' => 3700, str_repeat('b', 32) . '.json' => 3500, 'keep.txt' => 99999] as $name => $age) {
            file_put_contents($folder . '/' . $name, '{}');
            touch($folder . '/' . $name, $now - $age);
        }
        same(1, $store->prune(3600, $now));
        $left = array_values(array_diff(scandir($folder), ['.', '..']));
        sort($left);
        same([str_repeat('b', 32) . '.json', 'keep.txt'], $left);
    } finally {
        removeFolder($folder);
    }
});

// ---------------------------------------------------------------------------
// Limit per destination
// ---------------------------------------------------------------------------

test('limit: N texts per destination per window', function (): void {
    $folder = temporaryFolder();
    try {
        $limiter = new RateLimiter($folder . '/sent', 2, 3600);
        $now = 1_800_000_000;
        same(true, $limiter->reserve('8452632561', $now), 'first');
        same(true, $limiter->reserve('8452632561', $now + 10), 'second');
        same(false, $limiter->reserve('8452632561', $now + 20), 'third is refused');
        same(true, $limiter->reserve('8455550000', $now + 20), 'another number is independent');
        same(false, $limiter->reserve('8452632561', $now + 3599), 'still refused just before the window ends');
        same(true, $limiter->reserve('8452632561', $now + 3601), 'allowed again when the first text has aged out');
        same(false, $limiter->reserve('8452632561', $now + 3605), 'refused: two texts inside the window again');
        same('[' . ($now + 10) . ',' . ($now + 3601) . ']', file_get_contents($folder . '/sent/8452632561.json'), 'old entries are dropped and refusals are not counted');
    } finally {
        removeFolder($folder);
    }
});

test('limit: a text that was not sent after all is taken back', function (): void {
    $folder = temporaryFolder();
    try {
        $limiter = new RateLimiter($folder . '/sent', 1, 3600);
        $now = 1_800_000_000;
        same(true, $limiter->reserve('8452632561', $now));
        same(false, $limiter->reserve('8452632561', $now + 1));
        $limiter->release('8452632561', $now);
        same('[]', file_get_contents($folder . '/sent/8452632561.json'));
        same(true, $limiter->reserve('8452632561', $now + 2), 'free again');

        $limiter->release('8452632561', $now + 99);
        same('[' . ($now + 2) . ']', file_get_contents($folder . '/sent/8452632561.json'), 'a time that was never counted changes nothing');
        $limiter->release('8455550000', $now);
        ok(!is_file($folder . '/sent/8455550000.json'), 'no file appears for a number that was never counted');

        $two = new RateLimiter($folder . '/sent', 5, 3600);
        same(true, $two->reserve('2015551234', $now));
        same(true, $two->reserve('2015551234', $now));
        $two->release('2015551234', $now);
        same('[' . $now . ']', file_get_contents($folder . '/sent/2015551234.json'), 'one release takes back one text');

        same(true, $two->reserve('2015551234', $now + 500));
        $two->release('2015551234', $now);
        same('[' . ($now + 500) . ']', file_get_contents($folder . '/sent/2015551234.json'), 'texts counted later are not touched');
        file_put_contents($folder . '/sent/2015551234.json', 'not json');
        $two->release('2015551234', $now + 500);
        same('not json', file_get_contents($folder . '/sent/2015551234.json'), 'a damaged file is left for the next text to replace');
    } finally {
        removeFolder($folder);
    }
});

test('limit: 0 switches it off and odd input never blocks a text', function (): void {
    $folder = temporaryFolder();
    try {
        $off = new RateLimiter($folder . '/sent', 0, 3600);
        same(true, $off->reserve('8452632561'), 'no limit');
        $off->release('8452632561', time());
        ok(!is_dir($folder . '/sent'), 'nothing is stored when the limit is off');

        $limiter = new RateLimiter($folder . '/sent', 1, 3600);
        same(true, $limiter->reserve('../../etc/passwd'), 'not a number: ignored');
        $limiter->release('../../etc/passwd', time());
        ok(!is_dir($folder . '/sent'), 'no file for a non-number');

        $now = 1_800_000_000;
        mkdir($folder . '/sent');
        file_put_contents($folder . '/sent/8452632561.json', 'not json');
        same(true, $limiter->reserve('8452632561', $now), 'a damaged file does not block');
        same('[' . $now . ']', file_get_contents($folder . '/sent/8452632561.json'), 'and is replaced');
        file_put_contents($folder . '/sent/8452632561.json', '["x", 9999999999, null]');
        same(true, $limiter->reserve('8452632561', $now), 'entries that are not past times are ignored');
    } finally {
        removeFolder($folder);
    }
});

test('limit: an unwritable folder is reported, not fatal', function (): void {
    $folder = temporaryFolder();
    try {
        file_put_contents($folder . '/blocker', 'x');
        $limiter = new RateLimiter($folder . '/blocker/sent', 1, 3600);
        same(null, $limiter->reserve('8452632561'), 'the text may go out, but it is not counted');
        same(null, $limiter->reserve('8452632561'), 'again');
        $limiter->release('8452632561', time());
    } finally {
        removeFolder($folder);
    }
});

test('limit: stale records are deleted', function (): void {
    $folder = temporaryFolder();
    try {
        $now = 1_800_000_000;
        foreach (['8452632561.json' => 3700, '8455550000.json' => 100, 'readme.txt' => 99999] as $name => $age) {
            file_put_contents($folder . '/' . $name, '[]');
            touch($folder . '/' . $name, $now - $age);
        }
        same(1, (new RateLimiter($folder, 3, 3600))->prune($now));
        $left = array_values(array_diff(scandir($folder), ['.', '..']));
        sort($left);
        same(['8455550000.json', 'readme.txt'], $left);
    } finally {
        removeFolder($folder);
    }
});
