<?php

declare(strict_types=1);

/**
 * AAtoSMS - NetSapiens Web Responder entry point.
 *
 * This is the only file the PBX talks to. Point the To-Web dial translation at
 *
 *     https://your-server/aatosms/index.php?key=YOUR_WEBRESPONDER_KEY&SMSNumber=8457747000
 *
 * All settings live in the .env file one folder above this one, or in the folder
 * that env-path.txt there names. Nothing in here depends on where the project
 * folder is, so it can be moved freely.
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    error_log('AAtoSMS needs PHP 8.2 or newer; this server runs PHP ' . PHP_VERSION);
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>';
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

AAtoSMS\App::run(dirname(__DIR__));
