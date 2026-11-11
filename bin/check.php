<?php

declare(strict_types=1);

/**
 * Installation check for the command line.
 *
 *   php bin/check.php
 *       PHP, settings (.env) and the storage folder.
 *
 *   php bin/check.php AccountDomain=1180.DynaCloud AccountUser=9000
 *       Also reads that auto attendant and shows the text that would be sent.
 *
 *   php bin/check.php AccountDomain=1180.DynaCloud AccountUser=9000 SMSNumber=8457747000 SendTo=8452632561
 *       Also sends ONE real text to the SendTo number.
 *
 * The names are the ones used in the To-Web URL and sent by the PBX.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, 'AAtoSMS needs PHP 8.2 or newer; this is PHP ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

const USAGE = <<<'TEXT'
Usage: php bin/check.php [Name=value ...]

  (no values)            check PHP, the .env settings and the storage folder
  AccountDomain=<domain> domain of the auto attendant, e.g. 1180.DynaCloud
  AccountUser=<ext>      auto attendant extension, e.g. 9000 -> reads its message text
  SMSNumber=<number>     number the text is sent from
  SendTo=<number>        sends ONE real text to this number
  SMSOption=<key>  SMSUser=<user>  AAUser=<ext>  AAPrompt=<name>  NmsDnis=<number>
                         same meaning as in the To-Web URL

TEXT;

$known = ['accountdomain', 'accountuser', 'smsnumber', 'sendto', 'smsoption', 'smsuser', 'aauser', 'aaprompt', 'nmsdnis'];
$values = [];
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['-h', '--help', '/?', 'help'], true)) {
        echo USAGE;
        exit(0);
    }
    if (preg_match('/\A([A-Za-z]+)=(.*)\z/s', $argument, $match) !== 1 || !in_array(strtolower($match[1]), $known, true)) {
        fwrite(STDERR, 'Not understood: ' . $argument . PHP_EOL . PHP_EOL . USAGE);
        exit(2);
    }
    $values[$match[1]] = $match[2];
}

$check = new AAtoSMS\Diagnostics(dirname(__DIR__));
echo $check->run(new AAtoSMS\Request([], $values, 'GET'), true);
echo PHP_EOL,
    'This tested the PHP of the command line. The web server may use another php.ini and', PHP_EOL,
    'another Windows account; to test that one, open in a browser:', PHP_EOL,
    '  https://<your server>/<path>/index.php?key=<WEBRESPONDER_KEY>&check=1', PHP_EOL;

exit($check->problems() > 0 ? 1 : 0);
