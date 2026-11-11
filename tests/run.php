<?php

declare(strict_types=1);

/**
 * Runs every test:  php tests/run.php
 *
 * Offline: no PBX, no network, no real text messages. Works the same on Windows
 * and Linux. Exit code 0 means everything passed.
 */

namespace AAtoSMS\Tests;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

// Any PHP warning, notice or deprecation fails the test that caused it.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return true;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support.php';

$started = microtime(true);
require __DIR__ . '/unit.php';
require __DIR__ . '/calls.php';
require __DIR__ . '/check.php';

exit(finish('', $started));
