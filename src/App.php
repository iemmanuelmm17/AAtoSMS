<?php

declare(strict_types=1);

namespace AAtoSMS;

/**
 * Receives one Web Responder request and answers with XML.
 *
 * A call is a series of requests. The first one comes from the To-Web URL and
 * must carry the key; it reads the auto attendant, stores the call state and
 * plays the first prompt. Every later request is the "action" of a <Gather>:
 * it carries call=<id>.<step> plus the keys the caller pressed.
 */
final class App
{
    /** State of a call is kept this long; a call never lasts that long. */
    public const CALL_TTL_SECONDS = 3600;

    private CallFlow $flow;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private NetSapiens $api,
        private CallStore $calls,
        private RateLimiter $limiter,
    ) {
        $this->flow = new CallFlow($config, $api, $limiter, $logger);
    }

    /** Builds the application from the .env file of a project folder. */
    public static function fromConfig(Config $config, Logger $logger, ?callable $transport = null): self
    {
        return new self(
            $config,
            $logger,
            new NetSapiens($config->apiUrl, $config->apiToken, $config->apiTimeout, $config->apiCaFile, $transport, $logger),
            new CallStore($config->storagePath . '/calls'),
            new RateLimiter($config->storagePath . '/sent', $config->limitPerDestination, $config->limitWindowMinutes * 60),
        );
    }

    public static function loggerFor(Config $config): Logger
    {
        $logger = new Logger(
            $config->storagePath . '/logs',
            $config->logLevel,
            $config->logTimezone !== '' ? new \DateTimeZone($config->logTimezone) : null,
        );
        $logger->addSecret($config->apiToken);
        $logger->addSecret($config->responderKey);

        return $logger;
    }

    /**
     * Web entry point (public/index.php). Always answers with XML and never
     * shows a PHP error to the PBX: problems are written to the log instead.
     */
    public static function run(string $projectRoot): void
    {
        ini_set('display_errors', '0');
        $logger = new Logger(rtrim($projectRoot, '/\\') . '/var/logs');
        $answered = false;

        set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$logger): bool {
            if ((error_reporting() & $severity) !== 0) {
                $logger->warning('PHP: ' . $message, ['at' => basename($file) . ':' . $line]);
            }

            return true;
        });
        register_shutdown_function(static function () use (&$logger, &$answered): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            $logger->error('PHP fatal error: ' . $error['message'], ['at' => basename($error['file']) . ':' . $error['line']]);
            if (!$answered && !headers_sent()) {
                self::unavailable()->send();
            }
        });

        $app = null;
        try {
            $config = Config::load($projectRoot);
            $logger = self::loggerFor($config);
            $app = self::fromConfig($config, $logger);

            $response = $app->handle(Request::fromGlobals());
        } catch (\Throwable $error) {
            $logger->error('Request failed: ' . $error->getMessage(), Logger::describe($error));
            $response = self::unavailable();
        }

        $answered = true;
        $response->send();

        // Housekeeping after the answer is out, on about one request in twenty.
        if ($app !== null && random_int(1, 20) === 1) {
            flush();
            $app->cleanUp();
        }
    }

    public function handle(Request $request): Response
    {
        if (!in_array($request->method, ['GET', 'POST'], true)) {
            return new Response(405, '', ['Allow' => 'GET, POST']);
        }

        try {
            $token = $request->get('call');
            $self = $this->selfUrl($request);

            return $token === '' ? $this->startCall($request, $self) : $this->continueCall($request, $token, $self);
        } catch (\Throwable $error) {
            // Storage not writable, damaged state, a bug: the caller still gets a spoken answer.
            $this->logger->error('Request failed: ' . $error->getMessage(), Logger::describe($error));

            return new Response(200, $this->flow->failure(''));
        }
    }

    /** Removes expired call state, old send records and old log files. */
    public function cleanUp(?int $now = null): void
    {
        try {
            $this->calls->prune(self::CALL_TTL_SECONDS, $now);
            $this->limiter->prune($now);
            $this->logger->prune($this->config->logRetentionDays, $now);
        } catch (\Throwable $error) {
            $this->logger->warning('Housekeeping failed: ' . $error->getMessage());
        }
    }

    private function startCall(Request $request, string $self): Response
    {
        if (!hash_equals($this->config->responderKey, $request->get('key'))) {
            $this->logger->warning('Request rejected: the key in the URL is missing or wrong', ['ip' => $request->ip]);

            return new Response(403, Xml::document('', Xml::say('Access denied.'), Xml::hangup()));
        }

        if ($request->get('check') !== '') {
            // index.php?key=...&check=1 shows the installation check as seen by the web server's PHP.
            $this->logger->info('Installation check requested', ['ip' => $request->ip]);
            $report = (new Diagnostics($this->config->projectRoot, $this->config, $this->api))->run($request);

            return new Response(200, $report, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $ref = bin2hex(random_bytes(4));
        $this->logger->setCallRef($ref);
        $this->logger->debug('New call', $request->snapshot());

        $call = [];
        try {
            $call = CallPlan::source($request, $this->config);
            $call += CallPlan::sender($request, $this->config, $call['aa_user']);
            $call['caller'] = PhoneNumber::normalize($request->get('NmsAni')) ?? '';
            $message = AutoAttendant::script(
                $this->api->autoAttendant($call['domain'], $call['aa_user'], $call['prompt']),
                $call['option']
            );
        } catch (CallSetupException | AutoAttendantException $error) {
            $this->logger->error('Call cannot start: ' . $error->getMessage(), $call + ['ip' => $request->ip]);

            return new Response(200, $this->flow->failure($ref));
        } catch (ApiException $error) {
            $this->logger->error('Call cannot start: ' . $error->getMessage(), $call + [
                'http_status' => $error->status,
                'api_response' => $error->responseBody,
                'hint' => $error->hint(),
            ]);

            return new Response(200, $this->flow->failure($ref));
        }

        $session = $this->calls->create($call + [
            'ref' => $ref,
            'created' => time(),
            'call_id' => $request->get('OrigCallID'),
            'message' => $message,
            'stage' => 'menu',
            'attempts' => 0,
            'pending' => '',
            'sms' => 'none',
            'step' => 1,
            'responses' => [],
        ]);
        $xml = $this->flow->start($session->state, self::callback($self, $session->id, 1));
        $session->save();
        $session->close();

        $this->logger->info('Call started', $call + [
            'nms_ani' => $request->get('NmsAni'),
            'nms_dnis' => $request->get('NmsDnis'),
            'message_characters' => Text::length($message),
            'call_id' => $request->get('OrigCallID'),
        ]);
        $this->logger->debug('Answer', ['xml' => $xml]);

        return new Response(200, $xml);
    }

    private function continueCall(Request $request, string $token, string $self): Response
    {
        $session = preg_match('/\A([a-f0-9]{32})\.([0-9]{1,3})\z/', $token, $parts) === 1 ? $this->calls->open($parts[1]) : null;
        if ($session === null) {
            $this->logger->warning('Request rejected: unknown or expired call', ['ip' => $request->ip]);

            return new Response(200, $this->flow->failure(''));
        }

        try {
            $state = &$session->state;
            $step = (int) $parts[2];
            $this->logger->setCallRef((string) $state['ref']);

            if (isset($state['responses'][$step])) {
                // The PBX asked for the same step again: repeat the answer, do nothing twice.
                $this->logger->debug('Step repeated; saved answer returned', ['step' => $step]);

                return new Response(200, (string) $state['responses'][$step]);
            }
            if ($step !== $state['step']) {
                $this->logger->warning('Request rejected: step out of sequence', ['step' => $step, 'expected' => $state['step']]);

                return new Response(200, $this->flow->failure((string) $state['ref']));
            }

            $xml = $this->flow->advance(
                $state,
                $request->get('Digits'),
                self::callback($self, $session->id, $step + 1),
                static function () use ($session): void {
                    $session->save();
                }
            );
            $state['responses'][$step] = $xml;
            $state['step'] = $step + 1;
            $session->save();
            $this->logger->debug('Answer', ['xml' => $xml]);

            return new Response(200, $xml);
        } finally {
            // Releases the lock on the call, also when something above failed.
            $session->close();
        }
    }

    /**
     * Address of this script, where the PBX sends the keys of a <Gather>: the path
     * it was reached at (same host), or below APP_URL when that is set.
     */
    private function selfUrl(Request $request): string
    {
        $script = $request->path !== '' ? $request->path : '/index.php';

        return $this->config->appUrl !== '' ? $this->config->appUrl . '/' . basename($script) : $script;
    }

    /** URL the PBX requests after the next <Gather>. One parameter, so no "&" is needed. */
    private static function callback(string $self, string $id, int $step): string
    {
        return $self . '?call=' . $id . '.' . $step;
    }

    /** Answer used when the application itself is broken (no .env, PHP error). */
    private static function unavailable(): Response
    {
        return new Response(200, Xml::document('', Xml::say(Config::DEFAULT_PROMPTS['error']), Xml::hangup()));
    }
}
