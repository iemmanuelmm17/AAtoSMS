<?php

declare(strict_types=1);

/** Whole calls, request by request, exactly as the PBX would drive them. */

namespace AAtoSMS\Tests;

use AAtoSMS\Config;

const MENU = 'To receive a text message at the number you are calling from, eight, four, five, two, six, three, two, five, six, one, press 1. To enter a different mobile number, press 2.';
const ENTER = 'Please enter the ten digit mobile number, starting with the area code.';
const CONFIRM_5551234 = 'You entered two, zero, one, five, five, five, one, two, three, four. To send the text message to this number, press 1. To enter a different number, press 2.';
const SENT = 'Your text message has been sent. Thank you for calling. Goodbye.';
const SENT_REPEAT = 'Your text message has been sent. Returning to the main menu.';
const GOODBYE = 'We did not receive a valid response. Goodbye.';
const ERROR = 'We are sorry, we are unable to send the text message at this time. Please try again later. Goodbye.';

// ---------------------------------------------------------------------------
// The two ways to get a text
// ---------------------------------------------------------------------------

test('call: press 1 to text the number the caller is calling from', function (): void {
    $bench = new Bench();

    $menu = $bench->call();
    same(200, $menu->status);
    same(MENU, spoken($menu));
    $gather = gather($menu);
    same('dtmf', $gather['input']);
    same('1', $gather['numDigits']);
    same('6', $gather['timeout']);
    same('5', $gather['digitEndTimeout']);
    ok(preg_match('~\A/aatosms/index\.php\?call=[a-f0-9]{32}\.1\z~', $gather['action']) === 1, 'callback is this script with one call parameter: ' . $gather['action']);
    lacks(Bench::KEY, $menu->body, 'answer');

    // The auto attendant of extension 9000 was read: prompt name follows the 4-digit rule.
    same(1, count($bench->api->requests));
    same('GET', $bench->api->requests[0]['method']);
    same('https://pbx.example.test/ns-api/v2/domains/1180.DynaCloud/users/9000/autoattendants/Prompt_900001', $bench->api->requests[0]['url']);
    same('Authorization: Bearer ' . Bench::TOKEN, $bench->api->requests[0]['headers'][1]);

    $done = $bench->press('1');
    same(200, $done->status);
    same(SENT, spoken($done));
    ok(hangsUp($done), 'the call is hung up after the text');
    same(null, forwardsTo($done), 'and not forwarded: the address has no repeat=yes');

    $posts = $bench->api->requestsOf('POST');
    same(1, count($posts));
    same('https://pbx.example.test/ns-api/v2/domains/1180.DynaCloud/users/9000/messages', $posts[0]['url']);
    same(['Accept: application/json', 'Authorization: Bearer ' . Bench::TOKEN, 'Content-Type: application/json'], $posts[0]['headers']);
    same(
        [['type' => 'sms', 'message' => Bench::SCRIPT, 'destination' => '18452632561', 'from-number' => '18457747000']],
        $bench->api->texts(),
        'text = script of option 2, to the caller (NmsAni), from the dialed number (NmsDnis)'
    );

    $log = $bench->log();
    contains('INFO    [', $log);
    contains('Call started {"domain":"1180.DynaCloud","aa_user":"9000","prompt":"Prompt_900001","option":"2","sms_user":"9000","from":"8457747000","caller":"8452632561","repeat":false,"nms_ani":"8452632561","nms_dnis":"8457747000","message_characters":117,"call_id":"20261111-abc123","ip":"203.0.113.9"}', $log);
    contains('Text sent {"to":"18452632561","from":"18457747000","domain":"1180.DynaCloud","sms_user":"9000","entered_by_caller":false,"http_status":200,"api_response":"{\"code\":202,\"message\":\"Accepted\"}","characters":117}', $log);
});

test('call: press 2 to type a different number, confirm it, then text it', function (): void {
    $bench = new Bench();
    $bench->call();

    $enter = $bench->press('2');
    same(ENTER, spoken($enter));
    same('10', gather($enter)['numDigits'], 'ten keys are collected');
    ok(str_ends_with(gather($enter)['action'], '.2'), 'second callback');

    $confirm = $bench->press('2015551234');
    same(CONFIRM_5551234, spoken($confirm));
    same('1', gather($confirm)['numDigits']);
    same(0, count($bench->api->requestsOf('POST')), 'nothing is sent before the number is confirmed');

    $done = $bench->press('1');
    same(SENT, spoken($done));
    ok(hangsUp($done), 'hung up');
    same(
        [['type' => 'sms', 'message' => Bench::SCRIPT, 'destination' => '12015551234', 'from-number' => '18457747000']],
        $bench->api->texts()
    );
    contains('"to":"12015551234"', $bench->log());
    contains('"entered_by_caller":true', $bench->log());
});

test('call: the typed number can be corrected before sending', function (): void {
    $bench = new Bench();
    $bench->call();
    $bench->press('2');
    $bench->press('2015551234');
    same(ENTER, spoken($bench->press('2')), 'pressing 2 at the confirmation asks for the number again');
    contains('You entered nine, one, seven, five, five, five, zero, one, zero, zero.', spoken($bench->press('9175550100')));
    same(SENT, spoken($bench->press('1')));
    same('19175550100', $bench->api->texts()[0]['destination']);
    same(1, count($bench->api->texts()));
});

test('call: the PBX may use POST, with its values in the body and ours in the URL', function (): void {
    $bench = new Bench();
    $menu = $bench->last = $bench->send(
        ['NmsAni' => '8452632561', 'NmsDnis' => '8457747000', 'AccountUser' => '9000', 'AccountDomain' => '1180.DynaCloud', 'Digits' => ''],
        ['key' => Bench::KEY, 'SMSNumber' => '8455550199'],
        'POST'
    );
    same(MENU, spoken($menu));
    same(SENT, spoken($bench->press('1', 'POST')));
    same('18455550199', $bench->api->texts()[0]['from-number']);
});

// ---------------------------------------------------------------------------
// Where the values come from
// ---------------------------------------------------------------------------

test('SMSNumber: from the URL, from the POST body, or NmsDnis when blank', function (): void {
    $from = static function (array $changes, string $method = 'GET'): string {
        $bench = new Bench();
        $bench->call($changes, $method);
        $bench->press('1', $method);

        return $bench->api->texts()[0]['from-number'];
    };
    same('18455550199', $from(['SMSNumber' => '8455550199']), 'URL');
    same('18455550199', $from(['SMSNumber' => '8455550199'], 'POST'), 'POST body');
    same('18455550199', $from(['SMSNumber' => '+1 (845) 555-0199']), 'punctuation is fine');
    same('18455550199', $from(['SMSNumber' => '18455550199']), '11 digits are fine');
    same('18457747000', $from(['SMSNumber' => '']), 'blank: NmsDnis');
    same('18457747000', $from(['SMSNumber' => null]), 'absent: NmsDnis');
    same('18457747000', $from(['SMSNumber' => null, 'NmsDnis' => '18457747000']), 'NmsDnis with country code');
});

test('SMSNumber: a wrong value stops the call instead of sending from an unintended number', function (): void {
    $bench = new Bench();
    $answer = $bench->call(['SMSNumber' => '845774700']);
    same(ERROR, spoken($answer));
    ok(hangsUp($answer), 'hung up');
    same(0, count($bench->api->requests), 'the API is not called');
    contains('ERROR   [', $bench->log());
    contains('SMSNumber "845774700" is not a valid 10-digit number', $bench->log());
});

test('SMSNumber blank and NmsDnis not a full number: explained in the log', function (): void {
    $bench = new Bench();
    $answer = $bench->call(['NmsDnis' => '9000']);
    same(ERROR, spoken($answer));
    contains('SMSNumber is blank and NmsDnis "9000" is not a valid 10-digit number', $bench->log());
    contains('Add SMSNumber=<number> to the To-Web URL', $bench->log());
});

test('parameter names are not case-sensitive', function (): void {
    $bench = new Bench();
    $bench->last = $bench->send(['KEY' => Bench::KEY, 'nmsani' => '8452632561', 'nmsdnis' => '8457747000', 'accountuser' => '9000', 'accountdomain' => '1180.DynaCloud', 'smsnumber' => '8455550199']);
    same(MENU, spoken($bench->last));
    $bench->press('1');
    same(['destination' => '18452632561', 'from-number' => '18455550199'], array_intersect_key($bench->api->texts()[0], ['destination' => 1, 'from-number' => 1]));
});

test('3-digit auto attendant extension uses the 001 prompt name', function (): void {
    $bench = new Bench();
    $bench->call(['AccountUser' => '900']);
    same('https://pbx.example.test/ns-api/v2/domains/1180.DynaCloud/users/900/autoattendants/Prompt_900001', $bench->api->requests[0]['url']);
    $bench->press('1');
    same('https://pbx.example.test/ns-api/v2/domains/1180.DynaCloud/users/900/messages', $bench->api->requestsOf('POST')[0]['url']);
});

test('optional URL parameters: option, SMSUser, AAUser, AAPrompt', function (): void {
    $bench = new Bench();
    $bench->call(['option' => '5', 'SMSUser' => '7003', 'AccountUser' => '7777', 'AAUser' => '8002', 'AAPrompt' => 'Prompt_800202']);
    same('https://pbx.example.test/ns-api/v2/domains/1180.DynaCloud/users/8002/autoattendants/Prompt_800202', $bench->api->requests[0]['url']);
    $bench->press('1');
    same('https://pbx.example.test/ns-api/v2/domains/1180.DynaCloud/users/7003/messages', $bench->api->requestsOf('POST')[0]['url']);
    same('uploaded message', $bench->api->texts()[0]['message']);

    $star = new Bench();
    $star->call(['option' => '*']);
    $star->press('1');
    same("Star \"quoted\" & <tagged> text.\nLine two with caf\u{e9}.", $star->api->texts()[0]['message'], 'the text is sent unchanged');
    same("{\"type\":\"sms\",\"message\":\"Star \\\"quoted\\\" & <tagged> text.\\nLine two with caf\u{e9}.\",\"destination\":\"18452632561\",\"from-number\":\"18457747000\"}", $star->api->requestsOf('POST')[0]['body']);
});

test('option: the key whose message is sent - in the URL or the POST body, else SMS_SCRIPT_OPTION, else key 2', function (): void {
    // Keys of the stand-in auto attendant that hold a text: 2, 5, 6, 9 and *.
    $sent = static function (array $changes, array $env = [], string $method = 'GET'): string {
        $bench = new Bench($env);
        $bench->call($changes, $method);
        $bench->press('1', $method);
        same(1, count($bench->api->texts()), 'one text for ' . json_encode($changes));

        return $bench->calls()[0]['option'] . ': ' . $bench->api->texts()[0]['message'];
    };

    same('2: ' . Bench::SCRIPT, $sent([]), 'no option: key 2');
    same('2: ' . Bench::SCRIPT, $sent(['option' => '2']));
    same('5: uploaded message', $sent(['option' => '5']));
    same('6: For billing, press 1.', $sent(['option' => '6']));
    same('9: First part. Second part.', $sent(['option' => '9']));
    same('5: uploaded message', $sent(['option' => '5'], [], 'POST'), 'in the POST body');
    same('5: uploaded message', $sent(['OPTION' => '5']), 'the name is not case-sensitive');
    same('5: uploaded message', $sent(['Option' => ' 5 ']), 'spaces around the value do not count');

    same('2: ' . Bench::SCRIPT, $sent(['option' => '']), 'blank: as if it was not there');
    same('5: uploaded message', $sent([], ['SMS_SCRIPT_OPTION' => '5']), 'no option: SMS_SCRIPT_OPTION of .env');
    same('5: uploaded message', $sent(['option' => ''], ['SMS_SCRIPT_OPTION' => '5']), 'blank: SMS_SCRIPT_OPTION of .env');
    same('9: First part. Second part.', $sent(['option' => '9'], ['SMS_SCRIPT_OPTION' => '5']), 'the URL wins over .env');

    // As the PBX sends it with <HttpMethod=POST>: its values in the body, ours in the URL.
    $bench = new Bench();
    $bench->last = $bench->send(
        ['NmsAni' => '8452632561', 'NmsDnis' => '8457747000', 'AccountUser' => '9000', 'AccountDomain' => '1180.DynaCloud', 'Digits' => ''],
        ['key' => Bench::KEY, 'option' => '9'],
        'POST'
    );
    $bench->press('1', 'POST');
    same('First part. Second part.', $bench->api->texts()[0]['message'], 'option in the URL of a POST request');
    contains('Call started {"domain":"1180.DynaCloud","aa_user":"9000","prompt":"Prompt_900001","option":"9",', $bench->log());
});

test('option: every key from 0 to 9 can be named', function (): void {
    $api = json_decode(fixture('autoattendant.json'), true);
    foreach (range(0, 9) as $key) {
        $api['auto-attendant']['option-' . $key] = $api['auto-attendant']['option-5'];
        $api['auto-attendant']['option-' . $key]['audio']['file-script-text'] = 'Message of key ' . $key . '.';
    }
    foreach (range(0, 9) as $key) {
        $bench = new Bench();
        $bench->api->autoAttendant = json_encode($api);
        $bench->call(['option' => (string) $key]);
        $bench->press('1');
        same('Message of key ' . $key . '.', $bench->api->texts()[0]['message']);
        contains('"option":"' . $key . '",', $bench->log());
    }
});

test('SMSOption, the first name of option, still works; option comes first', function (): void {
    $sent = static function (array $changes, array $env = []): string {
        $bench = new Bench($env);
        $bench->call($changes);
        $bench->press('1');

        return $bench->api->texts()[0]['message'];
    };

    same('uploaded message', $sent(['SMSOption' => '5']));
    same('uploaded message', $sent(['smsoption' => '5'], ['SMS_SCRIPT_OPTION' => '9']), 'wins over .env, as before');
    same('First part. Second part.', $sent(['option' => '9', 'SMSOption' => '5']), 'both: option');
    same('uploaded message', $sent(['option' => '', 'SMSOption' => '5']), 'a blank option is as if it was not there');
    same("Star \"quoted\" & <tagged> text.\nLine two with caf\u{e9}.", $sent(['SMSOption' => '*']));
});

test('option: a value that is not a key ends the call, and no other key takes its place', function (): void {
    $cases = [
        [['option' => '10'], 'option "10" is not an auto attendant key (0-9 or *).'],
        [['option' => '22'], 'option "22" is not an auto attendant key (0-9 or *).'],
        [['option' => '-1'], 'option "-1" is not an auto attendant key (0-9 or *).'],
        [['option' => 'two'], 'option "two" is not an auto attendant key (0-9 or *).'],
        [['option' => '#'], 'option "#" is not an auto attendant key (0-9 or *).'],
        [['option' => '2.0'], 'option "2.0" is not an auto attendant key (0-9 or *).'],
        [['option' => 'x', 'SMSOption' => '5'], 'option "x" is not an auto attendant key (0-9 or *).'],
        [['option' => '', 'SMSOption' => '55'], 'SMSOption "55" is not an auto attendant key (0-9 or *).'],
    ];
    foreach ($cases as [$changes, $message]) {
        $bench = new Bench(['SMS_SCRIPT_OPTION' => '5']);
        $answer = $bench->call($changes);
        same(ERROR, spoken($answer), $message);
        ok(hangsUp($answer), 'hung up: ' . $message);
        same(0, count($bench->api->requests), 'the API is not asked: ' . $message);
        same([], $bench->calls(), 'no call is started: ' . $message);
        contains('Call cannot start: ' . $message, $bench->log());
    }
});

test('option 0 can be selected ("0" is a value, not a blank)', function (): void {
    $api = json_decode(fixture('autoattendant.json'), true);
    $api['auto-attendant']['option-0'] = $api['auto-attendant']['option-5'];

    $byUrl = new Bench();
    $byUrl->api->autoAttendant = json_encode($api);
    $byUrl->call(['option' => '0']);
    $byUrl->press('1');
    same('uploaded message', $byUrl->api->texts()[0]['message']);

    $byOldName = new Bench();
    $byOldName->api->autoAttendant = json_encode($api);
    $byOldName->call(['SMSOption' => '0']);
    $byOldName->press('1');
    same('uploaded message', $byOldName->api->texts()[0]['message']);

    $byEnv = new Bench(['SMS_SCRIPT_OPTION' => '0']);
    $byEnv->api->autoAttendant = json_encode($api);
    $byEnv->call();
    $byEnv->press('1');
    same('uploaded message', $byEnv->api->texts()[0]['message']);
});

test('settings: SMS_USER, SMS_SCRIPT_OPTION, NS_DEFAULT_DOMAIN and the number formats', function (): void {
    $bench = new Bench(['SMS_USER' => '7003', 'SMS_SCRIPT_OPTION' => '5', 'NS_DEFAULT_DOMAIN' => 'fallback.example', 'SMS_NUMBER_FORMAT' => '10digit']);
    $bench->call(['AccountDomain' => null]);
    same('https://pbx.example.test/ns-api/v2/domains/fallback.example/users/9000/autoattendants/Prompt_900001', $bench->api->requests[0]['url']);
    $bench->press('1');
    same('https://pbx.example.test/ns-api/v2/domains/fallback.example/users/7003/messages', $bench->api->requestsOf('POST')[0]['url']);
    same(['type' => 'sms', 'message' => 'uploaded message', 'destination' => '8452632561', 'from-number' => '8457747000'], $bench->api->texts()[0]);

    $e164 = new Bench(['SMS_NUMBER_FORMAT' => 'e164', 'SMS_USER' => '7003']);
    $e164->call(['SMSUser' => '7004']);
    $e164->press('1');
    same(['+18452632561', '+18457747000'], [$e164->api->texts()[0]['destination'], $e164->api->texts()[0]['from-number']]);
    ok(str_ends_with($e164->api->requestsOf('POST')[0]['url'], '/users/7004/messages'), 'SMSUser in the URL beats SMS_USER in .env');
});

test('the domain sent by the PBX wins over NS_DEFAULT_DOMAIN', function (): void {
    $bench = new Bench(['NS_DEFAULT_DOMAIN' => 'fallback.example']);
    $bench->call();
    contains('/domains/1180.DynaCloud/', $bench->api->requests[0]['url']);
});

test('how the number is read back follows CALLER_ID_DIGIT_STYLE', function (): void {
    $expected = [
        'words' => 'eight, four, five, two, six, three, two, five, six, one',
        'commas' => '8, 4, 5, 2, 6, 3, 2, 5, 6, 1',
        'hyphens' => '8-4-5-2-6-3-2-5-6-1',
        'spaces' => '8 4 5 2 6 3 2 5 6 1',
    ];
    foreach ($expected as $style => $number) {
        $bench = new Bench(['CALLER_ID_DIGIT_STYLE' => $style]);
        same('To receive a text message at the number you are calling from, ' . $number . ', press 1. To enter a different mobile number, press 2.', spoken($bench->call()), $style);
    }
    $bench = new Bench(['CALLER_ID_DIGIT_STYLE' => 'commas']);
    $bench->call();
    $bench->press('2');
    contains('You entered 2, 0, 1, 5, 5, 5, 1, 2, 3, 4.', spoken($bench->press('2015551234')), 'typed number too');
});

test('prompts, voice, language and timeouts come from the settings', function (): void {
    $bench = new Bench([
        'PROMPT_MENU' => 'Text {number}? Press 1 & wait, or <2>.',
        'PROMPT_SENT' => 'Done "ok".',
        'TTS_VOICE' => 'en-US_AllisonVoice',
        'TTS_LANGUAGE' => 'en-US',
        'INPUT_TIMEOUT_SECONDS' => '9',
        'INTERDIGIT_TIMEOUT_SECONDS' => '4',
        'CALLER_ID_DIGIT_STYLE' => 'spaces',
    ]);
    $menu = $bench->call();
    same('Text 8 4 5 2 6 3 2 5 6 1? Press 1 & wait, or <2>.', spoken($menu));
    contains('<Say voice="en-US_AllisonVoice" language="en-US">Text 8 4 5 2 6 3 2 5 6 1? Press 1 &amp; wait, or &lt;2&gt;.</Say>', $menu->body);
    same(['9', '4'], [gather($menu)['timeout'], gather($menu)['digitEndTimeout']]);
    same('Done "ok".', spoken($bench->press('1')));

    $plain = (new Bench())->call();
    contains('<Say>To receive', $plain->body, 'no voice or language attribute unless configured');
});

test('callback URLs: path on the same host by default, absolute with APP_URL', function (): void {
    $relative = new Bench([], '/index.php');
    ok(preg_match('~\A/index\.php\?call=[a-f0-9]{32}\.1\z~', gather($relative->call())['action']) === 1, 'path only');

    $absolute = new Bench(['APP_URL' => 'https://sms.example.test/aatosms/']);
    $action = gather($absolute->call())['action'];
    ok(preg_match('~\Ahttps://sms\.example\.test/aatosms/index\.php\?call=[a-f0-9]{32}\.1\z~', $action) === 1, 'absolute: ' . $action);
    same(SENT, spoken($absolute->press('1')));

    // Behind a proxy the public address differs from the local one: APP_URL replaces the folder, the file name stays.
    $proxied = new Bench(['APP_URL' => 'https://sms.example.test/public-name'], '/local/folder/responder.php');
    $action = gather($proxied->call())['action'];
    ok(preg_match('~\Ahttps://sms\.example\.test/public-name/responder\.php\?call=[a-f0-9]{32}\.1\z~', $action) === 1, 'proxied: ' . $action);

    // APP_URL may also be the address of the script itself, the way it is written in the To-Web URL.
    $script = new Bench(['APP_URL' => 'http://192.0.2.10/aatosms/public/index.php'], '/aatosms/public/index.php');
    $action = gather($script->call())['action'];
    ok(preg_match('~\Ahttp://192\.0\.2\.10/aatosms/public/index\.php\?call=[a-f0-9]{32}\.1\z~', $action) === 1, 'script: ' . $action);
    same(ENTER, spoken($script->press('2')));
    ok(str_starts_with(gather($script->last)['action'], 'http://192.0.2.10/aatosms/public/index.php?call='), 'every step');

    // Without a web server (no script path) the usual file name is assumed.
    $app = \AAtoSMS\App::fromConfig($relative->config, \AAtoSMS\App::loggerFor($relative->config), new FakeApi());
    $answer = $app->handle(new \AAtoSMS\Request([], ['key' => Bench::KEY, 'NmsAni' => '8452632561', 'NmsDnis' => '8457747000', 'AccountUser' => '9000', 'AccountDomain' => '1180.DynaCloud'], 'GET'));
    ok(str_starts_with(gather($answer)['action'], '/index.php?call='), 'no path: ' . gather($answer)['action']);
});

test('no usable caller ID: the caller is asked for a number right away', function (): void {
    foreach (['Anonymous', '', '9000', 'Restricted', '1234567890', null] as $ani) {
        $bench = new Bench();
        $first = $bench->call(['NmsAni' => $ani]);
        same('We can send you a text message. ' . ENTER, spoken($first), var_export($ani, true));
        same('10', gather($first)['numDigits']);
        $bench->press('2015551234');
        same(SENT, spoken($bench->press('1')));
        same('12015551234', $bench->api->texts()[0]['destination']);
    }
});

test('keys present on the very first request are ignored', function (): void {
    $bench = new Bench();
    same(MENU, spoken($bench->call(['Digits' => '1'])));
    same(0, count($bench->api->requestsOf('POST')));
});

// ---------------------------------------------------------------------------
// Wrong keys and silence
// ---------------------------------------------------------------------------

test('wrong key at the menu: the prompt is repeated, then the call ends politely', function (): void {
    $bench = new Bench();
    $bench->call();
    same('Sorry, that is not a valid selection. ' . MENU, spoken($bench->press('7')));
    same('1', gather($bench->last)['numDigits']);
    same('Sorry, that is not a valid selection. ' . MENU, spoken($bench->press('*')));
    $end = $bench->press('#');
    same(GOODBYE, spoken($end));
    ok(hangsUp($end), 'hung up after three tries');
    same(0, count($bench->api->requestsOf('POST')), 'no text');
    contains('Call ended without a text: no valid answer {"stage":"menu","attempts":3,"last":"invalid_choice"}', $bench->log());
});

test('silence: an empty answer repeats the prompt, three in a row end the call', function (): void {
    $bench = new Bench();
    $bench->call();
    same('Sorry, we did not receive a response. ' . MENU, spoken($bench->press('')));
    same('Sorry, we did not receive a response. ' . MENU, spoken($bench->press('')));
    $end = $bench->press('');
    same(GOODBYE, spoken($end));
    ok(hangsUp($end), 'hung up');
    same(0, count($bench->api->requestsOf('POST')));
});

test('a valid answer resets the count of failed tries', function (): void {
    $bench = new Bench();
    $bench->call();
    $bench->press('9');
    $bench->press('');
    same(ENTER, spoken($bench->press('2')), 'third try is valid');
    same('Sorry, that is not a valid ten digit number. ' . ENTER, spoken($bench->press('123')));
    same('Sorry, we did not receive a response. ' . ENTER, spoken($bench->press('')));
    contains('You entered', spoken($bench->press('2015551234')));
    same('Sorry, that is not a valid selection. ' . CONFIRM_5551234, spoken($bench->press('5')));
    same('Sorry, we did not receive a response. ' . CONFIRM_5551234, spoken($bench->press('')));
    same(SENT, spoken($bench->press('1')));
    same(1, count($bench->api->texts()));
});

test('MAX_ATTEMPTS sets how often a prompt is played', function (): void {
    $once = new Bench(['MAX_ATTEMPTS' => '1']);
    $once->call();
    same(GOODBYE, spoken($once->press('')));

    $five = new Bench(['MAX_ATTEMPTS' => '5']);
    $five->call();
    foreach ([1, 2, 3, 4] as $try) {
        ok(gather($five->press('')) !== null, 'try ' . $try . ' repeats the prompt');
    }
    same(GOODBYE, spoken($five->press('')));
});

test('typed numbers: what is accepted and what is not', function (): void {
    $answerTo = static function (string $digits): string {
        $bench = new Bench();
        $bench->call();
        $bench->press('2');

        return spoken($bench->press($digits));
    };
    foreach (['2015551234', '2015551234#', '12015551234', ' 2015551234 '] as $digits) {
        same(CONFIRM_5551234, $answerTo($digits), $digits);
    }
    foreach (['201555123', '0015551234', '2010551234', '201555123*', '*', '20155512345', '2015551234*', '#', '201-555-1234', '+12015551234', '(201)5551234', '120155512345'] as $digits) {
        same('Sorry, that is not a valid ten digit number. ' . ENTER, $answerTo($digits), $digits);
    }
    same('Sorry, we did not receive a response. ' . ENTER, $answerTo(''));
});

test('a number typed with the leading 1: the eleventh key is awaited in silence', function (): void {
    $bench = new Bench(['INTERDIGIT_TIMEOUT_SECONDS' => '4']);
    $bench->call();
    $bench->press('2');

    // Ten keys were collected, and the caller is about to press the last one.
    $wait = $bench->press('1201555123');
    same('', spoken($wait), 'nothing is said');
    $gather = gather($wait);
    same(['dtmf', '1', '4', '4'], [$gather['input'], $gather['numDigits'], $gather['timeout'], $gather['digitEndTimeout']], 'one more key, as long as between two keys');
    ok(str_ends_with($gather['action'], '.3'), 'third callback');
    same(0, count($bench->api->requestsOf('POST')));

    same(CONFIRM_5551234, spoken($bench->press('4')));
    same(SENT, spoken($bench->press('1')));
    same('12015551234', $bench->api->texts()[0]['destination']);

    // The last key does not come, or what was typed is not a number: one failed try, asked again.
    foreach (['' => '1201555123', '#' => '1201555123', '*' => '1201555123', '4' => '1001555123', '0' => '1201055123', '45' => '1201555123'] as $last => $first) {
        $again = new Bench();
        $again->call();
        $again->press('2');
        same('', spoken($again->press($first)), $first);
        same('Sorry, that is not a valid ten digit number. ' . ENTER, spoken($again->press((string) $last)), $first . ' then "' . $last . '"');
        same('10', gather($again->last)['numDigits']);

        // What was typed before is forgotten: the next ten keys are a number of their own.
        same(CONFIRM_5551234, spoken($again->press('2015551234')), 'after ' . $first . ' then "' . $last . '"');
        same('', $again->calls()[0]['typed']);
    }

    // Each of them counts as one try.
    $three = new Bench();
    $three->call();
    $three->press('2');
    foreach ([1, 2] as $try) {
        $three->press('1201555123');
        ok(gather($three->press('')) !== null, 'try ' . $try . ' asks again');
    }
    $three->press('1201555123');
    same(GOODBYE, spoken($three->press('')));
    same(0, count($three->api->requestsOf('POST')));
});

test('the pound key after a typed number is not taken as an answer', function (): void {
    $bench = new Bench();
    $bench->call();
    $bench->press('2');
    $bench->press('2015551234');

    // It arrives as the answer to "You entered ...", before that could be heard.
    same(CONFIRM_5551234, spoken($bench->press('#')), 'asked again, without an apology');
    same('1', gather($bench->last)['numDigits']);
    same('Sorry, that is not a valid selection. ' . CONFIRM_5551234, spoken($bench->press('#')), 'only once');
    same(SENT, spoken($bench->press('1')));
    same(1, count($bench->api->texts()));

    // It does not use up a try, and every number that is typed gets the same treatment.
    $once = new Bench(['MAX_ATTEMPTS' => '1']);
    $once->call();
    $once->press('2');
    $once->press('2015551234');
    same(CONFIRM_5551234, spoken($once->press('##')));
    same(ENTER, spoken($once->press('2')));
    $once->press('9175550100');
    contains('You entered nine, one, seven,', spoken($once->press('#')));
    lacks('Sorry', spoken($once->last), 'second number');
    same(GOODBYE, spoken($once->press('#')));

    // Also after a number that was typed with the leading 1 and the pound key.
    $both = new Bench();
    $both->call();
    $both->press('2');
    $both->press('1201555123');
    $both->press('4');
    same(CONFIRM_5551234, spoken($both->press('#')));
    same(SENT, spoken($both->press('1')));

    // At the first menu no number was typed: there the pound key is a wrong key like any other.
    $menu = new Bench();
    $menu->call();
    same('Sorry, that is not a valid selection. ' . MENU, spoken($menu->press('#')));
});

test('three bad numbers end the call without a text', function (): void {
    $bench = new Bench();
    $bench->call();
    $bench->press('2');
    $bench->press('555');
    $bench->press('');
    $end = $bench->press('0123456789');
    same(GOODBYE, spoken($end));
    ok(hangsUp($end), 'hung up');
    same(0, count($bench->api->requestsOf('POST')));
});

test('a call that goes back and forth forever is stopped', function (): void {
    $bench = new Bench();
    $bench->call();
    $steps = 0;
    while (gather($bench->last) !== null && $steps < 100) {
        $bench->press(match (true) {
            str_contains(spoken($bench->last), 'You entered') || str_contains(spoken($bench->last), 'calling from') => '2',
            default => '2015551234',
        });
        $steps++;
    }
    same(40, $steps, 'steps before the forced goodbye');
    same(GOODBYE, spoken($bench->last));
    same(0, count($bench->api->requestsOf('POST')));
});

// ---------------------------------------------------------------------------
// After the text: hang up, or back to the auto attendant (repeat=yes)
// ---------------------------------------------------------------------------

test('repeat=yes: after the text the call is forwarded back to the auto attendant', function (): void {
    $bench = new Bench(['LOG_LEVEL' => 'info']);
    same(MENU, spoken($bench->call(['repeat' => 'yes'])), 'the questions are the same');

    $done = $bench->press('1');
    same(200, $done->status);
    same(SENT_REPEAT, spoken($done), 'no goodbye: the call goes on');
    same('sip:9000@1180.DynaCloud', forwardsTo($done), 'to the auto attendant: its extension, in the domain of the call');
    ok(!hangsUp($done), 'no hang up');
    contains('.</Say><Forward>sip:9000@1180.DynaCloud</Forward></Response>', $done->body, 'said first, forwarded after that');
    same(
        [['type' => 'sms', 'message' => Bench::SCRIPT, 'destination' => '18452632561', 'from-number' => '18457747000']],
        $bench->api->texts(),
        'the text is the same as without repeat'
    );
    same(['done', 'sent', true], [$bench->calls()[0]['stage'], $bench->calls()[0]['sms'], $bench->calls()[0]['repeat']]);

    $lines = explode("\n", trim($bench->log()));
    same(3, count($lines), $bench->log());
    contains('Call started {"domain":"1180.DynaCloud","aa_user":"9000","prompt":"Prompt_900001","option":"2","sms_user":"9000","from":"8457747000","caller":"8452632561","repeat":true,', $lines[0]);
    contains('Text sent {"to":"18452632561"', $lines[1]);
    contains('INFO    [', $lines[2]);
    contains('Forwarding the call back to the auto attendant {"to":"sip:9000@1180.DynaCloud"}', $lines[2]);
});

test('repeat=yes: also after a number that the caller typed', function (): void {
    $bench = new Bench();
    $bench->call(['repeat' => 'yes']);
    same(ENTER, spoken($bench->press('2')));
    same(CONFIRM_5551234, spoken($bench->press('2015551234')));
    $done = $bench->press('1');
    same(SENT_REPEAT, spoken($done));
    same('sip:9000@1180.DynaCloud', forwardsTo($done));
    same('12015551234', $bench->api->texts()[0]['destination']);
});

test('repeat: yes forwards; no, blank and absent hang up; in the URL or in the POST body', function (): void {
    $ending = static function (array $changes, string $method = 'GET'): string {
        $bench = new Bench();
        $bench->call($changes, $method);
        $done = $bench->press('1', $method);
        same(1, count($bench->api->texts()), 'the text goes out either way');
        lacks('neither yes nor no', $bench->log(), 'log');

        return match (true) {
            forwardsTo($done) !== null && !hangsUp($done) => 'forward to ' . forwardsTo($done) . ': ' . spoken($done),
            hangsUp($done) => 'hang up: ' . spoken($done),
            default => 'neither: ' . $done->body,
        };
    };

    foreach (['yes', 'YES', 'Yes', ' yes ', 'y', 'true', 'on', '1'] as $value) {
        same('forward to sip:9000@1180.DynaCloud: ' . SENT_REPEAT, $ending(['repeat' => $value]), var_export($value, true));
    }
    foreach ([null, '', ' ', 'no', 'NO', 'No', 'n', 'false', 'off', '0'] as $value) {
        same('hang up: ' . SENT, $ending(['repeat' => $value]), var_export($value, true));
    }
    same('forward to sip:9000@1180.DynaCloud: ' . SENT_REPEAT, $ending(['repeat' => 'yes'], 'POST'), 'POST body');
    same('forward to sip:9000@1180.DynaCloud: ' . SENT_REPEAT, $ending(['REPEAT' => 'yes']), 'the name is not case-sensitive');
    same('forward to sip:9000@1180.DynaCloud: ' . SENT_REPEAT, $ending(['Repeat' => 'Yes'], 'POST'), 'the name is not case-sensitive');

    // As the PBX sends it with <HttpMethod=POST>: its values in the body, ours in the URL.
    $bench = new Bench();
    $bench->last = $bench->send(
        ['NmsAni' => '8452632561', 'NmsDnis' => '8457747000', 'AccountUser' => '9000', 'AccountDomain' => '1180.DynaCloud', 'Digits' => ''],
        ['key' => Bench::KEY, 'SMSNumber' => '8455550199', 'repeat' => 'yes'],
        'POST'
    );
    same('sip:9000@1180.DynaCloud', forwardsTo($bench->press('1', 'POST')), 'repeat=yes in the URL of a POST request');
});

test('repeat=yes: the call goes back to the auto attendant that the text was read from', function (): void {
    $other = new Bench();
    $other->call(['AccountUser' => '7777', 'AAUser' => '8002', 'AAPrompt' => 'Prompt_800202', 'SMSOption' => '5', 'SMSUser' => '7003', 'repeat' => 'yes']);
    $done = $other->press('1');
    same('sip:8002@1180.DynaCloud', forwardsTo($done), 'AAUser, not AccountUser and not SMSUser');
    contains('Forwarding the call back to the auto attendant {"to":"sip:8002@1180.DynaCloud"}', $other->log());

    $short = new Bench();
    $short->call(['AccountUser' => '900', 'repeat' => 'yes']);
    same('sip:900@1180.DynaCloud', forwardsTo($short->press('1')), '3-digit extension');
});

test('repeat=yes: the domain of the forward is the AccountDomain that the PBX sends', function (): void {
    // In the URL of a GET request ...
    $get = new Bench();
    $get->call(['AccountDomain' => 'Acme-2.example', 'repeat' => 'yes']);
    same('sip:9000@Acme-2.example', forwardsTo($get->press('1')), 'written as the PBX wrote it');
    contains('/domains/Acme-2.example/users/9000/messages', $get->api->requestsOf('POST')[0]['url'], 'the domain the text was sent in');
    contains('Forwarding the call back to the auto attendant {"to":"sip:9000@Acme-2.example"}', $get->log());

    // ... and in the body, as the PBX sends it with <HttpMethod=POST>: its values in the body, ours in the URL.
    $post = new Bench();
    $post->last = $post->send(
        ['NmsAni' => '8452632561', 'NmsDnis' => '8457747000', 'AccountUser' => '7777', 'AccountDomain' => '2290.Other', 'Digits' => ''],
        ['key' => Bench::KEY, 'AAUser' => '8002', 'AAPrompt' => 'Prompt_800202', 'SMSOption' => '5', 'repeat' => 'yes'],
        'POST'
    );
    same('sip:8002@2290.Other', forwardsTo($post->press('1', 'POST')), 'AAUser of the URL in the AccountDomain of the body');

    // The body comes first, as for every value.
    $both = new Bench();
    $both->last = $both->send(
        ['NmsAni' => '8452632561', 'NmsDnis' => '8457747000', 'AccountUser' => '9000', 'AccountDomain' => 'body.example', 'Digits' => ''],
        ['key' => Bench::KEY, 'AccountDomain' => 'url.example', 'repeat' => 'yes'],
        'POST'
    );
    same('sip:9000@body.example', forwardsTo($both->press('1', 'POST')));

    // A PBX that sends no domain: NS_DEFAULT_DOMAIN of .env, the domain the text is sent in.
    $default = new Bench(['NS_DEFAULT_DOMAIN' => 'fallback.example']);
    $default->call(['AccountDomain' => null, 'repeat' => 'yes']);
    same('sip:9000@fallback.example', forwardsTo($default->press('1')));
    $default->call(['repeat' => 'yes']);
    same('sip:9000@1180.DynaCloud', forwardsTo($default->press('1')), 'the domain of the request wins over NS_DEFAULT_DOMAIN');
});

test('repeat=yes: a domain or an extension that cannot be part of an address ends the call before anything is sent', function (): void {
    $cases = [
        ['AccountDomain' => '1180.DynaCloud;user=phone'],
        ['AccountDomain' => 'x@evil.example'],
        ['AccountDomain' => '1180.DynaCloud:5060'],
        ['AccountDomain' => 'a b'],
        ['AccountDomain' => 'a>b'],
        ['AAUser' => '9000@evil.example'],
        ['AAUser' => 'sip:9000'],
        ['AAUser' => '9000;x=1'],
        ['AccountUser' => '9000?x=1'],
    ];
    foreach ($cases as $changes) {
        $bench = new Bench();
        $answer = $bench->call($changes + ['repeat' => 'yes']);
        same(ERROR, spoken($answer), json_encode($changes));
        ok(hangsUp($answer) && forwardsTo($answer) === null, 'hung up: ' . json_encode($changes));
        same(0, count($bench->api->requests), 'the API is not asked: ' . json_encode($changes));
        same([], $bench->calls(), 'no call is started');
        contains('Call cannot start: ', $bench->log());
    }
});

test('repeat=yes: the wording comes from PROMPT_SENT_REPEAT, with the voice of the other prompts', function (): void {
    $bench = new Bench(['PROMPT_SENT_REPEAT' => 'Sent & "done". Back to <the> menu.', 'PROMPT_SENT' => 'Sent. Bye.', 'TTS_VOICE' => 'female', 'TTS_LANGUAGE' => 'en-US']);
    $bench->call(['repeat' => 'yes']);
    $done = $bench->press('1');
    same('Sent & "done". Back to <the> menu.', spoken($done));
    contains('<Say voice="female" language="en-US">Sent &amp; &quot;done&quot;. Back to &lt;the&gt; menu.</Say><Forward>sip:9000@1180.DynaCloud</Forward>', $done->body);

    $bench->call();
    same('Sent. Bye.', spoken($bench->press('1')), 'without repeat the usual wording stays');
});

test('repeat=yes: a call that ends without a text is hung up as before', function (): void {
    $ended = static function (Bench $bench, $answer, string $heard, string $what): void {
        same($heard, spoken($answer), $what);
        ok(hangsUp($answer), $what . ': hung up');
        same(null, forwardsTo($answer), $what . ': not forwarded');
        lacks('<Forward', $answer->body, $what);
        lacks('Forwarding the call', $bench->log(), $what);
    };

    $refused = new Bench();
    $refused->api->postAnswers = [FakeApi::answer(400, '{"code":400,"message":"from-number is not an SMS number of this user"}')];
    $refused->call(['repeat' => 'yes']);
    $ended($refused, $refused->press('1'), ERROR, 'the API refuses the text');

    $limited = new Bench(['SMS_LIMIT_PER_DESTINATION' => '1']);
    $limited->call(['repeat' => 'yes']);
    same('sip:9000@1180.DynaCloud', forwardsTo($limited->press('1')), 'the first text goes out');
    $limited->call(['repeat' => 'yes']);
    $second = $limited->press('1');
    same(1, count($limited->api->texts()), 'the caller came back and pressed the key again: no second text');
    same('A text message was already sent to this number recently. Please check your messages, or try again later. Goodbye.', spoken($second));
    ok(hangsUp($second) && forwardsTo($second) === null, 'limit reached: hung up');

    $silent = new Bench();
    $silent->call(['repeat' => 'yes']);
    $silent->press('');
    $silent->press('9');
    $ended($silent, $silent->press(''), GOODBYE, 'no valid answer');

    $unreadable = new Bench();
    $unreadable->api->getAnswers = [FakeApi::answer(404, '{"code":404,"message":"Not Found"}')];
    $ended($unreadable, $unreadable->call(['repeat' => 'yes']), ERROR, 'the auto attendant cannot be read');
});

test('repeat: a value that is neither yes nor no is pointed out in the log, and the text is still sent', function (): void {
    foreach (['maybe', 'yess', 'yes,no', '2', '"yes"'] as $value) {
        $bench = new Bench(['LOG_LEVEL' => 'info']);
        same(MENU, spoken($bench->call(['repeat' => $value])), 'the call starts: ' . $value);
        $done = $bench->press('1');
        same(SENT, spoken($done), $value);
        ok(hangsUp($done) && forwardsTo($done) === null, 'hung up, as with repeat=no: ' . $value);
        same(1, count($bench->api->texts()), 'the text went out: ' . $value);

        $lines = explode("\n", trim($bench->log()));
        same(3, count($lines), $bench->log());
        contains('WARNING [', $lines[0]);
        contains('] repeat in the To-Web URL is neither yes nor no: this call is hung up after the text ' . json_encode(['repeat' => $value], JSON_UNESCAPED_SLASHES), $lines[0]);
        contains('"repeat":false,', $lines[1]);
    }
});

test('repeat=yes: a step that the PBX asks for again is answered the same way, without a second text', function (): void {
    $bench = new Bench();
    $bench->call(['repeat' => 'yes']);
    $callback = $bench->callbackQuery();

    $first = $bench->send(['Digits' => '1'], $callback);
    $second = $bench->send(['Digits' => '1'], $callback);
    same('sip:9000@1180.DynaCloud', forwardsTo($first));
    same($first->body, $second->body);
    same(1, count($bench->api->texts()), 'exactly one text');

    // Nothing in the answer leads back here. A request for a later step ends the call.
    $next = $bench->send(['Digits' => '1'], ['call' => substr($callback['call'], 0, 33) . '2']);
    same('', spoken($next));
    ok(hangsUp($next), 'only a hang up');
    same(1, count($bench->api->texts()));
});

test('repeat=yes: when the text went out but the answer was lost, the repeated step forwards and sends nothing', function (): void {
    $bench = new Bench();
    $bench->call(['repeat' => 'yes']);

    // What is on disk when PHP stops right after the API accepted the text.
    $file = glob($bench->config->storagePath . '/calls/*.json')[0];
    $state = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    same(['none', 1, []], [$state['sms'], $state['step'], $state['responses']]);
    $state['sms'] = 'sent';
    $state['destination'] = '8452632561';
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));

    $again = $bench->press('1');
    same(SENT_REPEAT, spoken($again));
    same('sip:9000@1180.DynaCloud', forwardsTo($again));
    same(0, count($bench->api->requestsOf('POST')), 'the API is not asked to send it again');
    contains('Send step repeated; not sending again {"previous_result":"sent"}', $bench->log());
});

test('a call that was in progress while the application was updated is hung up after the text', function (): void {
    $bench = new Bench();
    $bench->call();

    // The state of a call that an older version started says nothing about repeat.
    $file = glob($bench->config->storagePath . '/calls/*.json')[0];
    $state = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    same(false, $state['repeat']);
    unset($state['repeat']);
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));

    $done = $bench->press('1');
    same(SENT, spoken($done));
    ok(hangsUp($done) && forwardsTo($done) === null, 'hung up');
    same(1, count($bench->api->texts()));
});

// ---------------------------------------------------------------------------
// One text per call, whatever the PBX repeats
// ---------------------------------------------------------------------------

test('a repeated request gets the same answer and never a second text', function (): void {
    $bench = new Bench();
    $bench->call();
    $callback = $bench->callbackQuery();

    $first = $bench->send(['Digits' => '1'], $callback);
    $second = $bench->send(['Digits' => '1'], $callback);
    $third = $bench->send(['Digits' => '2'], $callback, 'POST');
    same(SENT, spoken($first));
    same($first->body, $second->body);
    same($first->body, $third->body, 'even with different keys: the step was already answered');
    same(1, count($bench->api->texts()), 'exactly one text');
    contains('Step repeated; saved answer returned', $bench->log());

    $other = new Bench();
    $other->call();
    $step1 = $other->callbackQuery();
    $enter = $other->press('2');
    same($enter->body, $other->send(['Digits' => '2'], $step1)->body, 'an earlier step can be repeated too');
    $other->last = $enter;
    same(CONFIRM_5551234, spoken($other->press('2015551234')), 'and the call simply continues');
});

test('after the hang up nothing more happens', function (): void {
    $bench = new Bench();
    $bench->call();
    $callback = $bench->callbackQuery();
    $bench->press('1');
    $next = $bench->send(['Digits' => '1'], ['call' => substr($callback['call'], 0, 33) . '2']);
    same('', spoken($next));
    ok(hangsUp($next), 'only a hang up');
    same(1, count($bench->api->texts()));
});

test('a step out of sequence or an unknown call ends with the apology, without a text', function (): void {
    $bench = new Bench();
    $bench->call();
    $id = substr($bench->callbackQuery()['call'], 0, 32);

    $ahead = $bench->send(['Digits' => '1'], ['call' => $id . '.5']);
    same(ERROR, spoken($ahead));
    ok(hangsUp($ahead), 'hung up');
    contains('step out of sequence {"step":5,"expected":1}', $bench->log());

    foreach ([str_repeat('0', 32) . '.1', $id, $id . '.', $id . '.x', $id . '.1.1', 'x', '../../etc/passwd.1', strtoupper($id) . '.1', $id . '.1234'] as $token) {
        $answer = $bench->send(['Digits' => '1'], ['call' => $token]);
        same(200, $answer->status);
        same(ERROR, spoken($answer), $token);
        ok(hangsUp($answer), 'hung up');
    }
    contains('unknown or expired call', $bench->log());
    same(0, count($bench->api->requestsOf('POST')));

    same(SENT, spoken($bench->press('1')), 'the real call is not disturbed');
});

test('an interrupted send is never repeated', function (): void {
    $bench = new Bench();
    $bench->call();
    $callback = $bench->callbackQuery();
    $bench->api->onPost = static function (): array {
        throw new \RuntimeException('PHP was stopped in the middle of the API call');
    };

    $crashed = $bench->send(['Digits' => '1'], $callback);
    same(ERROR, spoken($crashed), 'the caller still gets an answer');
    same('sending', $bench->calls()[0]['sms'], 'the attempt was written down before the API call');
    contains('Request failed: PHP was stopped in the middle of the API call', $bench->log());

    $bench->api->onPost = null;
    $again = $bench->send(['Digits' => '1'], $callback);
    same(ERROR, spoken($again));
    ok(hangsUp($again), 'hung up');
    same(1, count($bench->api->requestsOf('POST')), 'the API was called once in total');
    contains('Send step repeated; not sending again {"previous_result":"sending"}', $bench->log());
});

// ---------------------------------------------------------------------------
// Limit per destination
// ---------------------------------------------------------------------------

test('limit: the fourth text to one number within the hour is refused', function (): void {
    $bench = new Bench();
    foreach ([1, 2, 3] as $n) {
        $bench->call();
        same(SENT, spoken($bench->press('1')), 'text ' . $n);
    }
    $bench->call();
    $refused = $bench->press('1');
    same('A text message was already sent to this number recently. Please check your messages, or try again later. Goodbye.', spoken($refused));
    ok(hangsUp($refused), 'hung up');
    same(3, count($bench->api->texts()));
    contains('WARNING [', $bench->log());
    contains('Text not sent: limit for this destination reached {"to":"18452632561"', $bench->log());
    contains('"limit":3,"window_minutes":60}', $bench->log());

    // Other numbers are unaffected, also when typed by the same caller.
    $bench->call();
    $bench->press('2');
    $bench->press('2015551234');
    same(SENT, spoken($bench->press('1')));
    same(4, count($bench->api->texts()));
});

test('limit: counts typed numbers, can be changed and can be switched off', function (): void {
    $one = new Bench(['SMS_LIMIT_PER_DESTINATION' => '1']);
    $one->call(['NmsAni' => '2015551234']);
    same(SENT, spoken($one->press('1')));
    $one->call();
    $one->press('2');
    $one->press('2015551234');
    contains('already sent to this number recently', spoken($one->press('1')));
    same(1, count($one->api->texts()));

    $off = new Bench(['SMS_LIMIT_PER_DESTINATION' => '0']);
    foreach ([1, 2, 3, 4, 5] as $n) {
        $off->call();
        same(SENT, spoken($off->press('1')));
    }
    same(5, count($off->api->texts()));
});

test('limit: a text that failed does not count', function (): void {
    $bench = new Bench(['SMS_LIMIT_PER_DESTINATION' => '1']);
    $bench->api->postAnswers = [FakeApi::answer(500, 'oops'), FakeApi::answer(0, '', 'cURL error 28: Operation timed out')];
    foreach ([1, 2] as $n) {
        $bench->call();
        same(ERROR, spoken($bench->press('1')), 'failed text ' . $n);
        same('[]', file_get_contents($bench->config->storagePath . '/sent/8452632561.json'), 'taken back');
    }
    $bench->call();
    same(SENT, spoken($bench->press('1')));
    $bench->call();
    contains('already sent to this number recently', spoken($bench->press('1')), 'the text that went out counts');
    same(3, count($bench->api->requestsOf('POST')));
});

test('limit: when it cannot be counted the text still goes out, with a warning', function (): void {
    $bench = new Bench(['SMS_LIMIT_PER_DESTINATION' => '1']);
    $bench->call();
    file_put_contents($bench->config->storagePath . '/sent', 'a file where the folder should be');
    same(SENT, spoken($bench->press('1')));
    $bench->call();
    same(SENT, spoken($bench->press('1')), 'not limited either');
    same(2, count($bench->api->texts()));
    contains('WARNING [', $bench->log());
    contains('This text cannot be counted for the per-destination limit (storage not writable?)', $bench->log());
});

// ---------------------------------------------------------------------------
// Things that go wrong
// ---------------------------------------------------------------------------

test('wrong or missing key: refused before anything else happens', function (): void {
    foreach ([null, '', 'wrong', Bench::KEY . 'x', substr(Bench::KEY, 0, -1), strtoupper(Bench::KEY)] as $key) {
        $bench = new Bench();
        $answer = $bench->call(['key' => $key]);
        same(403, $answer->status, var_export($key, true));
        same('Access denied.', spoken($answer));
        ok(hangsUp($answer), 'hung up');
        same(0, count($bench->api->requests), 'no API call');
        same([], $bench->calls(), 'no call state');
        contains('WARNING [-] Request rejected: the key in the URL is missing or wrong {"ip":"203.0.113.9"}', $bench->log());
    }
});

// The list of the README: one server of the PBX and a block of them.
const ALLOWED = ['IP_ALLOW_LIST' => '64.113.255.88, 64.113.255.0/24'];

test('allow list: a request from another address is refused before anything else happens', function (): void {
    // Each of them with the right key and everything else a call needs.
    foreach (['203.0.113.9', '64.113.254.88', '64.113.0.88', '64.114.255.88', '127.0.0.1', '::1', '::ffff:203.0.113.9', '', 'unknown', '64.113.255.88, 203.0.113.9'] as $ip) {
        $bench = new Bench(ALLOWED);
        $bench->ip = $ip;
        $answer = $bench->call();
        same(403, $answer->status, var_export($ip, true));
        same('Access denied.', spoken($answer));
        ok(hangsUp($answer), 'hung up');
        same(0, count($bench->api->requests), 'no API call');
        same([], $bench->calls(), 'no call state');
        same(
            1,
            preg_match('/\A\S+ WARNING \[-\] Request rejected: this address is not on IP_ALLOW_LIST \{"ip":' . preg_quote((string) json_encode($ip), '/') . '\}\n\z/', $bench->log()),
            'the log has that one line, which names the address: ' . $bench->log()
        );
    }
});

test('allow list: the address is the one the web server reports - nothing in a request can name another', function (): void {
    $claims = ['ip' => '64.113.255.88', 'IP' => '64.113.255.88', 'REMOTE_ADDR' => '64.113.255.88', 'X-Forwarded-For' => '64.113.255.88', 'HTTP_X_FORWARDED_FOR' => '64.113.255.88', 'Client-IP' => '64.113.255.88'];
    foreach (['GET', 'POST'] as $method) {
        $bench = new Bench(ALLOWED);
        $answer = $bench->call($claims, $method);
        same(403, $answer->status, $method);
        same('Access denied.', spoken($answer));
        same(0, count($bench->api->requests), 'no API call');
        contains('Request rejected: this address is not on IP_ALLOW_LIST {"ip":"203.0.113.9"}', $bench->log());

        // And a server of the PBX is not locked out by such a value.
        $bench->ip = '64.113.255.88';
        same(200, $bench->call(['ip' => '203.0.113.9', 'REMOTE_ADDR' => '203.0.113.9'], $method)->status, $method);
    }
});

test('allow list: from an address on the list, a call goes as it always did', function (): void {
    foreach (['64.113.255.88', '64.113.255.0', '64.113.255.200', '64.113.255.255', '::ffff:64.113.255.7'] as $ip) {
        $bench = new Bench(ALLOWED);
        $bench->ip = $ip;
        $menu = $bench->call();
        same(200, $menu->status, $ip);
        contains('To receive a text message at the number you are calling from', spoken($menu));
        same(SENT, spoken($bench->press('1')), $ip);
        same([['type' => 'sms', 'message' => Bench::SCRIPT, 'destination' => '18452632561', 'from-number' => '18457747000']], $bench->api->texts());
        // The log of a call names the address, which is how to find out what belongs on the list.
        contains('"call_id":"20261111-abc123","ip":' . json_encode($ip) . "}\n", $bench->log());
        lacks('Request rejected', $bench->log(), 'log');
    }
});

test('allow list: the keys of the caller have to come from an address on the list as well', function (): void {
    $bench = new Bench(ALLOWED);
    $bench->ip = '64.113.255.88';
    $menu = $bench->call();
    $state = $bench->calls();
    same(1, count($state));

    // Somebody else has the callback address of this call.
    $bench->ip = '203.0.113.9';
    $refused = $bench->press('1');
    same(403, $refused->status);
    same('Access denied.', spoken($refused));
    ok(hangsUp($refused), 'hung up');
    same([], $bench->api->texts(), 'nothing is sent');
    same($state, $bench->calls(), 'the call is left as it was');
    contains('WARNING [-] Request rejected: this address is not on IP_ALLOW_LIST {"ip":"203.0.113.9"}', $bench->log());
    lacks('Keys received', $bench->log(), 'log');

    // The PBX goes on with the call, be it from another one of its servers.
    $bench->ip = '64.113.255.17';
    $bench->last = $menu;
    same(SENT, spoken($bench->press('1')));
    same(1, count($bench->api->texts()));
});

test('allow list and key: a request needs both', function (): void {
    foreach ([null, '', 'wrong', Bench::KEY . 'x'] as $key) {
        $bench = new Bench(ALLOWED);
        $bench->ip = '64.113.255.88';
        $answer = $bench->call(['key' => $key]);
        same(403, $answer->status, var_export($key, true));
        same('Access denied.', spoken($answer));
        ok(hangsUp($answer), 'hung up');
        same(0, count($bench->api->requests), 'no API call');
        same([], $bench->calls(), 'no call state');
        // The log tells the two reasons apart.
        contains('WARNING [-] Request rejected: the key in the URL is missing or wrong {"ip":"64.113.255.88"}', $bench->log());
        lacks('IP_ALLOW_LIST', $bench->log(), 'log');
    }
});

test('allow list without WEBRESPONDER_KEY: the address is all that is asked for', function (): void {
    foreach ([null, '', 'whatever', Bench::KEY] as $key) {
        $bench = new Bench(['WEBRESPONDER_KEY' => ''] + ALLOWED);
        $bench->ip = '64.113.255.201';
        $menu = $bench->call(['key' => $key]);
        same(200, $menu->status, var_export($key, true));
        contains('To receive a text message at the number you are calling from', spoken($menu));
        same(SENT, spoken($bench->press('1')));
        same(1, count($bench->api->texts()));
        lacks('Request rejected', $bench->log(), 'log');

        // No key does not mean open: another address is refused, whatever it sends.
        $bench->ip = '203.0.113.9';
        $refused = $bench->call(['key' => $key]);
        same(403, $refused->status);
        same('Access denied.', spoken($refused));
        same(1, count($bench->calls()), 'no second call state');
        same(1, count($bench->api->texts()));
    }
});

test('allow list: the installation check and every other kind of request are for those addresses only', function (): void {
    $bench = new Bench(ALLOWED);
    $check = ['key' => Bench::KEY, 'check' => '1'];

    foreach ([$bench->send($check), $bench->send($check, [], 'POST'), $bench->call([], 'PUT'), $bench->call([], 'OPTIONS'), $bench->send([])] as $answer) {
        same(403, $answer->status);
        same('Access denied.', spoken($answer));
        same([], array_diff_key($answer->headers, ['Content-Type' => '', 'Cache-Control' => '']), 'no header that tells what is served here');
    }
    lacks('Installation check requested', $bench->log(), 'log');
    same(5, substr_count($bench->log(), 'Request rejected: this address is not on IP_ALLOW_LIST {"ip":"203.0.113.9"}'));

    $bench->ip = '64.113.255.88';
    $report = $bench->send($check);
    same(200, $report->status);
    contains(' installation check', $report->body);
    same(405, $bench->call([], 'PUT')->status, 'on the list: the usual answer');
    same(403, $bench->send(['check' => '1'])->status, 'the installation check still needs the key');

    // Without a key in .env the installation check needs none either.
    $listOnly = new Bench(['WEBRESPONDER_KEY' => ''] + ALLOWED);
    same(403, $listOnly->send(['check' => '1'])->status);
    $listOnly->ip = '64.113.255.88';
    $report = $listOnly->send(['check' => '1']);
    same(200, $report->status);
    contains(' installation check', $report->body);
});

test('only GET and POST are served', function (): void {
    foreach (['PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'] as $method) {
        $bench = new Bench();
        $answer = $bench->call([], $method);
        same(405, $answer->status);
        same('', $answer->body);
        same(['Allow' => 'GET, POST'], $answer->headers);
        same(0, count($bench->api->requests));
    }
});

test('missing or malformed values from the PBX are explained in the log', function (): void {
    $cases = [
        [['AccountDomain' => null], 'The request has no AccountDomain and NS_DEFAULT_DOMAIN is not set in .env.'],
        [['AccountDomain' => '../etc'], 'AccountDomain "../etc" is not a valid domain name.'],
        [['AccountUser' => null], 'The request has no AccountUser (the auto attendant extension).'],
        [['AccountUser' => '90/00'], 'Auto attendant extension "90/00" is not valid.'],
        [['AccountUser' => '90000'], 'the naming rule only covers 3-digit and 4-digit extensions'],
        [['AccountUser' => 'attendant'], 'the naming rule only covers 3-digit and 4-digit extensions'],
        [['SMSOption' => '10'], 'SMSOption "10" is not an auto attendant key (0-9 or *).'],
        [['SMSUser' => 'a b'], 'SMSUser "a b" is not a valid user.'],
        [['AAPrompt' => '../x'], 'AAPrompt "../x" is not a valid prompt name.'],
    ];
    foreach ($cases as [$changes, $message]) {
        $bench = new Bench();
        $answer = $bench->call($changes);
        same(200, $answer->status);
        same(ERROR, spoken($answer), $message);
        ok(hangsUp($answer), 'hung up');
        same(0, count($bench->api->requests), 'no API call for: ' . $message);
        contains('Call cannot start: ', $bench->log());
        contains($message, $bench->log());
        same([], $bench->calls());
    }
});

test('a longer extension works when the prompt name is given in the URL', function (): void {
    $bench = new Bench();
    same(MENU, spoken($bench->call(['AccountUser' => '90000', 'AAPrompt' => 'Prompt_900001'])));
    same('https://pbx.example.test/ns-api/v2/domains/1180.DynaCloud/users/90000/autoattendants/Prompt_900001', $bench->api->requests[0]['url']);
});

test('the auto attendant cannot be read: apology, hang up, cause in the log', function (): void {
    $cases = [
        [FakeApi::answer(401, '{"code":401,"message":"Unauthorized"}'), ['returned HTTP 401', '"http_status":401', 'Unauthorized', 'The API rejected NS_API_TOKEN']],
        [FakeApi::answer(404, '{"code":404,"message":"Not found"}'), ['returned HTTP 404', 'check the domain, the user/extension and the prompt name', '"prompt":"Prompt_900001"']],
        [FakeApi::answer(0, '', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate'), ['cURL error 60', 'NS_API_CA_FILE']],
        [FakeApi::answer(200, '<html>Login</html>'), ['did not return an auto attendant', 'Login']],
    ];
    foreach ($cases as [$failure, $expected]) {
        $bench = new Bench();
        $bench->api->getAnswers = [$failure, $failure];
        $answer = $bench->call();
        same(200, $answer->status);
        same(ERROR, spoken($answer));
        ok(hangsUp($answer), 'hung up');
        same([], $bench->calls(), 'no call state');
        foreach ($expected as $text) {
            contains($text, $bench->log());
        }
        contains('ERROR   [', $bench->log());
    }
});

test('option 2 has no usable message: apology, hang up, cause in the log', function (): void {
    $cases = [
        ['3', 'Key 3 of the auto attendant has no message audio.'],
        ['1', 'Key 1 of the auto attendant has no message audio. destination-application: "to-callqueue".'],
        ['7', 'has no script text'],
        ['8', 'The auto attendant has nothing assigned to key 8.'],
    ];
    foreach ($cases as [$option, $message]) {
        $bench = new Bench(['SMS_SCRIPT_OPTION' => $option]);
        $answer = $bench->call();
        same(ERROR, spoken($answer));
        ok(hangsUp($answer), 'hung up');
        contains($message, $bench->log());
        contains('"prompt":"Prompt_900001"', $bench->log());
        same(1, count($bench->api->requests), 'only the read');
    }
});

test('the API refuses the text: apology, hang up, answer of the API in the log', function (): void {
    $bench = new Bench();
    $bench->api->postAnswers = [FakeApi::answer(400, '{"code":400,"message":"from-number is not an SMS number of this user"}')];
    $bench->call();
    $callback = $bench->callbackQuery();
    $answer = $bench->press('1');
    same(ERROR, spoken($answer));
    ok(hangsUp($answer), 'hung up');
    $log = $bench->log();
    contains('ERROR   [', $log);
    contains('Text not sent: ns-api POST /domains/1180.DynaCloud/users/9000/messages returned HTTP 400', $log);
    contains('"to":"18452632561","from":"18457747000"', $log);
    contains('from-number is not an SMS number of this user', $log);
    same('failed', $bench->calls()[0]['sms']);

    // The PBX repeating the step gets the same apology; the API is not called again.
    same($answer->body, $bench->send(['Digits' => '1'], $callback)->body);
    same(1, count($bench->api->requestsOf('POST')));
});

test('the API cannot be reached while sending: one attempt, apology', function (): void {
    $bench = new Bench();
    $bench->api->postAnswers = [FakeApi::answer(0, '', 'cURL error 28: Operation timed out after 10001 milliseconds')];
    $bench->call();
    same(ERROR, spoken($bench->press('1')));
    same(1, count($bench->api->requestsOf('POST')));
    contains('cURL error 28', $bench->log());
    contains('could not be reached in time', $bench->log());
});

test('storage that cannot be written: apology and a clear log line, not a crash', function (): void {
    $bench = new Bench();
    file_put_contents($bench->folder . '/blocker', 'x');
    $previous = ini_get('error_log');
    ini_set('error_log', $bench->folder . '/php-error.log');
    try {
        $config = Config::fromArray(['STORAGE_PATH' => $bench->folder . '/blocker/var', 'NS_API_URL' => 'https://pbx.example.test/ns-api/v2', 'NS_API_TOKEN' => Bench::TOKEN, 'WEBRESPONDER_KEY' => Bench::KEY], $bench->folder);
        $app = \AAtoSMS\App::fromConfig($config, \AAtoSMS\App::loggerFor($config), new FakeApi());
        $answer = $app->handle(new \AAtoSMS\Request([], ['key' => Bench::KEY, 'NmsAni' => '8452632561', 'NmsDnis' => '8457747000', 'AccountUser' => '9000', 'AccountDomain' => '1180.DynaCloud']));
        same(200, $answer->status);
        same(ERROR, spoken($answer));
        ok(hangsUp($answer), 'hung up');
        $fallback = (string) file_get_contents($bench->folder . '/php-error.log');
        contains('cannot write to the log folder', $fallback);
        contains('Request failed: Cannot create the call state folder', $fallback);
        lacks(Bench::TOKEN, $fallback, 'PHP error log');
    } finally {
        ini_set('error_log', (string) $previous);
    }
});

// ---------------------------------------------------------------------------
// Privacy and housekeeping
// ---------------------------------------------------------------------------

test('the token, the key and call ids never appear in the log or in an answer', function (): void {
    $bench = new Bench();
    $answers = [$bench->call(['SMSNumber' => '8455550199'])->body];
    $id = substr($bench->callbackQuery()['call'], 0, 32);
    $answers[] = $bench->press('2')->body;
    $answers[] = $bench->press('2015551234')->body;
    $answers[] = $bench->press('1')->body;
    $bench->call(['key' => 'wrong-key-wrong-key-wrong-key']);

    // Even when the API server echoes the secrets back in an error message.
    $bench->api->getAnswers = [FakeApi::answer(403, '{"code":403,"message":"token ' . Bench::TOKEN . ' may not read this (' . Bench::KEY . ')"}')];
    $bench->call();
    contains('Call cannot start: ns-api GET', $bench->log());
    contains('token [redacted] may not read this ([redacted])', $bench->log());

    $log = $bench->log();
    contains('DEBUG   [', $log, 'debug log is on in this test');
    contains('"key":"[redacted]"', $log);
    foreach ([Bench::TOKEN, Bench::KEY, $id, 'wrong-key-wrong-key-wrong-key', 'Bearer ' . Bench::TOKEN] as $secret) {
        lacks($secret, $log, 'log');
    }
    foreach ($answers as $body) {
        lacks(Bench::TOKEN, $body, 'answer');
        lacks(Bench::KEY, $body, 'answer');
    }
    ok(preg_match('/<!-- AAtoSMS [0-9.]+ ref=([a-f0-9]{8}) -->/', $answers[0], $match) === 1, 'answers carry the log reference');
    contains('[' . $match[1] . '] Call started', $log);
    contains('[' . $match[1] . '] Text sent', $log);
    foreach ($answers as $body) {
        contains('ref=' . $match[1], $body, 'same reference for the whole call');
    }
    lacks($match[1], $id, 'the reference is not part of the call id');
});

test('at the default log level a call writes two lines', function (): void {
    $bench = new Bench(['LOG_LEVEL' => 'info']);
    $bench->call();
    $bench->press('1');
    $lines = explode("\n", trim($bench->log()));
    same(2, count($lines), $bench->log());
    contains('Call started', $lines[0]);
    contains('Text sent', $lines[1]);
});

test('the saved state of a call holds what is needed and no secret', function (): void {
    $bench = new Bench();
    $bench->call();
    $bench->press('2');
    $state = $bench->calls()[0];
    same(
        ['domain', 'aa_user', 'prompt', 'option', 'sms_user', 'from', 'caller', 'repeat', 'ref', 'created', 'call_id', 'message', 'stage', 'attempts', 'pending', 'typed', 'pound', 'sms', 'step', 'responses'],
        array_keys($state)
    );
    same(['enter', 2, 'none', Bench::SCRIPT, '20261111-abc123'], [$state['stage'], $state['step'], $state['sms'], $state['message'], $state['call_id']]);
    $raw = (string) file_get_contents(glob($bench->config->storagePath . '/calls/*.json')[0]);
    lacks(Bench::TOKEN, $raw, 'state file');
    lacks(Bench::KEY, $raw, 'state file');
});

test('housekeeping removes finished calls, stale send records and old logs', function (): void {
    $bench = new Bench(['LOG_RETENTION_DAYS' => '7']);
    $bench->call();
    $bench->press('1');
    $storage = $bench->config->storagePath;
    $now = time();
    file_put_contents($storage . '/logs/aatosms-2020-01-01.log', 'old');
    touch($storage . '/logs/aatosms-2020-01-01.log', $now - 6 * 86400);

    $bench->app->cleanUp($now + 10);
    same(1, count(glob($storage . '/calls/*.json')), 'a fresh call is kept');
    same(1, count(glob($storage . '/sent/*.json')), 'a fresh send record is kept');
    same(2, count(glob($storage . '/logs/*.log')), 'logs inside the retention are kept');

    $bench->app->cleanUp($now + 3700);
    same(0, count(glob($storage . '/calls/*.json')), 'call state older than an hour is deleted');
    same(0, count(glob($storage . '/sent/*.json')), 'send records older than the window are deleted');
    same(2, count(glob($storage . '/logs/*.log')), 'logs are still inside the retention');

    $bench->app->cleanUp($now + 2 * 86400);
    $logs = array_map('basename', glob($storage . '/logs/*.log'));
    same(1, count($logs), 'the log that is now 8 days old is deleted');
    ok($logs[0] !== 'aatosms-2020-01-01.log', 'the log of today is the one that is kept');
});
