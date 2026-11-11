<?php

declare(strict_types=1);

/**
 * AAtoSMS bootstrap.
 *
 * Loads the application classes and nothing else, so it is safe to include from
 * the web entry point (public/index.php), the CLI tools (bin/) and the tests.
 * There is no Composer dependency: copying the project folder is the install.
 */

namespace AAtoSMS;

const VERSION = '1.0.0';

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Xml.php';
require_once __DIR__ . '/Text.php';
require_once __DIR__ . '/PhoneNumber.php';
require_once __DIR__ . '/AutoAttendant.php';
require_once __DIR__ . '/NetSapiens.php';
require_once __DIR__ . '/CallStore.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/CallPlan.php';
require_once __DIR__ . '/CallFlow.php';
require_once __DIR__ . '/Diagnostics.php';
require_once __DIR__ . '/App.php';
