<?php

declare(strict_types=1);

use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;
use Apntalk\EslReplay\Checkpoint\FilesystemCheckpointStore;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointService;
use Apntalk\EslReplay\Config\CheckpointConfig;
use Apntalk\EslReplay\Config\ExecutionConfig;
use Apntalk\EslReplay\Config\ReplayConfig;
use Apntalk\EslReplay\Config\StorageConfig;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Execution\OfflineReplayExecutor;
use Apntalk\EslReplay\Execution\ReplayHandlerRegistry;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Storage\ReplayArtifactStore;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use Apntalk\EslReplay\Tests\Fixtures\FakeReplayInjector;
use Apntalk\EslReplay\Tests\Fixtures\FakeReplayRecordHandler;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Opt-in live verification harness for apntalk/esl-replay.
 *
 * This script is intentionally separate from PHPUnit and CI:
 * - it uses a real FreeSWITCH ESL endpoint from .env.live.local
 * - it validates the existing replay package surface using live-derived artifacts
 * - it is not a production runtime component and must not grow into one
 *
 * The live actions are deliberately narrow:
 * - connect and authenticate to ESL
 * - run `api status`
 * - run `bgapi status`
 * - observe the resulting BACKGROUND_JOB event
 *
 * Secrets from .env.live.local must never be printed or copied into repo files.
 */
final class LiveSuiteFailure extends RuntimeException
{
}

final readonly class LiveEnv
{
    public function __construct(
        public string $host,
        public int $port,
        public string $password,
    ) {}

    public static function load(string $filePath): self
    {
        if (!is_file($filePath)) {
            throw new LiveSuiteFailure("Live env file not found: {$filePath}");
        }

        $vars = [];
        foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = explode('=', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === '\'' && str_ends_with($value, '\'')))) {
                $value = substr($value, 1, -1);
            }

            $vars[$key] = $value;
        }

        $required = [
            'ESL_REPLAY_LIVE_HOST',
            'ESL_REPLAY_LIVE_PORT',
            'ESL_REPLAY_LIVE_PASSWORD',
        ];

        $missing = [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $vars) || trim((string) $vars[$key]) === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new LiveSuiteFailure(
                'Live env file is missing required variables: ' . implode(', ', $missing),
            );
        }

        if (!ctype_digit((string) $vars['ESL_REPLAY_LIVE_PORT'])) {
            throw new LiveSuiteFailure('ESL_REPLAY_LIVE_PORT must be a numeric TCP port.');
        }

        return new self(
            host: (string) $vars['ESL_REPLAY_LIVE_HOST'],
            port: (int) $vars['ESL_REPLAY_LIVE_PORT'],
            password: (string) $vars['ESL_REPLAY_LIVE_PASSWORD'],
        );
    }
}

final readonly class LiveArtifact implements CapturedArtifactEnvelope
{
    /**
     * @param array<string, string> $correlationIds
     * @param array<string, mixed> $runtimeFlags
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $artifactVersion,
        private string $artifactName,
        private DateTimeImmutable $captureTimestamp,
        private ?string $capturePath,
        private ?string $connectionGeneration,
        private ?string $sessionId,
        private ?string $jobUuid,
        private ?string $eventName,
        private array $correlationIds,
        private array $runtimeFlags,
        private array $payload,
    ) {}

    public function getArtifactVersion(): string
    {
        return $this->artifactVersion;
    }

    public function getArtifactName(): string
    {
        return $this->artifactName;
    }

    public function getCaptureTimestamp(): DateTimeImmutable
    {
        return $this->captureTimestamp;
    }

    public function getCapturePath(): ?string
    {
        return $this->capturePath;
    }

    public function getConnectionGeneration(): ?string
    {
        return $this->connectionGeneration;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getJobUuid(): ?string
    {
        return $this->jobUuid;
    }

    public function getEventName(): ?string
    {
        return $this->eventName;
    }

    public function getCorrelationIds(): array
    {
        return $this->correlationIds;
    }

    public function getRuntimeFlags(): array
    {
        return $this->runtimeFlags;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }
}

final readonly class EslFrame
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }
}

final class LiveEslClient
{
    /** @var resource */
    private $stream;

    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    public static function connect(LiveEnv $env, int $timeoutSeconds = 5): self
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            sprintf('tcp://%s:%d', $env->host, $env->port),
            $errno,
            $errstr,
            $timeoutSeconds,
        );

        if (!is_resource($stream)) {
            throw new LiveSuiteFailure(
                sprintf('Unable to connect to FreeSWITCH ESL endpoint: %s', $errstr !== '' ? $errstr : 'unknown error'),
            );
        }

        stream_set_timeout($stream, $timeoutSeconds);

        $client = new self($stream);
        $authRequest = $client->readFrame();
        if ($authRequest->header('Content-Type') !== 'auth/request') {
            throw new LiveSuiteFailure('Unexpected ESL greeting: expected auth/request frame.');
        }

        $authReply = $client->sendCommand('auth ' . $env->password);
        if (!str_starts_with((string) $authReply->header('Reply-Text'), '+OK')) {
            throw new LiveSuiteFailure('ESL authentication failed.');
        }

        return $client;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fwrite($this->stream, "exit\n\n");
            @fclose($this->stream);
        }
    }

    public function subscribeToBackgroundJobs(): void
    {
        $frame = $this->sendCommand('event plain BACKGROUND_JOB');
        $replyText = (string) ($frame->header('Reply-Text') ?? '');
        if (!str_starts_with($replyText, '+OK')) {
            throw new LiveSuiteFailure('Failed to subscribe to BACKGROUND_JOB events.');
        }
    }

    public function sendApi(string $command): EslFrame
    {
        $frame = $this->sendCommand('api ' . $command);
        if ($frame->header('Content-Type') !== 'api/response') {
            throw new LiveSuiteFailure('Unexpected api response frame type.');
        }

        return $frame;
    }

    /**
     * @return array{ack: EslFrame, jobUuid: string}
     */
    public function sendBgapi(string $command): array
    {
        $frame = $this->sendCommand('bgapi ' . $command);
        $replyText = (string) ($frame->header('Reply-Text') ?? '');

        $jobUuid = null;
        if (preg_match('/Job-UUID:\s*([A-Za-z0-9-]+)/', $replyText, $matches) === 1) {
            $jobUuid = $matches[1];
        }

        if ($jobUuid === null && preg_match('/Job-UUID:\s*([A-Za-z0-9-]+)/', $frame->body, $matches) === 1) {
            $jobUuid = $matches[1];
        }

        if ($jobUuid === null || $jobUuid === '') {
            throw new LiveSuiteFailure('bgapi response did not include a Job-UUID.');
        }

        return [
            'ack' => $frame,
            'jobUuid' => $jobUuid,
        ];
    }

    public function waitForBackgroundJob(string $jobUuid, int $timeoutSeconds = 10): EslFrame
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $frame = $this->readFrame();
            if ($frame->header('Content-Type') !== 'text/event-plain') {
                continue;
            }

            $payload = self::parsePlainEventBody($frame->body);
            if (($payload['Event-Name'] ?? null) !== 'BACKGROUND_JOB') {
                continue;
            }

            if (($payload['Job-UUID'] ?? null) !== $jobUuid) {
                continue;
            }

            return $frame;
        }

        throw new LiveSuiteFailure('Timed out waiting for BACKGROUND_JOB event.');
    }

    /**
     * @return array<string, string>
     */
    public static function parsePlainEventBody(string $body): array
    {
        $data = [];
        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $data[trim($key)] = ltrim($value);
        }

        return $data;
    }

    private function sendCommand(string $command): EslFrame
    {
        $written = @fwrite($this->stream, $command . "\n\n");
        if ($written === false) {
            throw new LiveSuiteFailure('Failed to write ESL command to the socket.');
        }

        return $this->readFrame();
    }

    private function readFrame(): EslFrame
    {
        $headers = [];

        while (true) {
            $line = fgets($this->stream);
            if ($line === false) {
                $meta = stream_get_meta_data($this->stream);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new LiveSuiteFailure('Timed out while reading from the ESL socket.');
                }

                throw new LiveSuiteFailure('Unexpected EOF while reading from the ESL socket.');
            }

            $line = rtrim($line, "\r\n");
            if ($line === '') {
                break;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $headers[trim($key)] = ltrim($value);
        }

        $contentLength = isset($headers['Content-Length']) ? (int) $headers['Content-Length'] : 0;
        $body = '';

        if ($contentLength > 0) {
            $remaining = $contentLength;
            while ($remaining > 0) {
                $chunk = fread($this->stream, $remaining);
                if ($chunk === false || $chunk === '') {
                    $meta = stream_get_meta_data($this->stream);
                    if (($meta['timed_out'] ?? false) === true) {
                        throw new LiveSuiteFailure('Timed out while reading an ESL frame body.');
                    }

                    throw new LiveSuiteFailure('Unexpected EOF while reading an ESL frame body.');
                }

                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
        }

        return new EslFrame($headers, $body);
    }
}

/**
 * @param list<StoredReplayRecord> $records
 * @return list<array<string, mixed>>
 */
function summarizeRecords(array $records): array
{
    return array_map(
        static fn (StoredReplayRecord $record): array => [
            'artifact_name' => $record->artifactName,
            'append_sequence' => $record->appendSequence,
            'session_id' => $record->sessionId,
            'connection_generation' => $record->connectionGeneration,
            'job_uuid' => $record->jobUuid,
            'event_name' => $record->eventName,
        ],
        $records,
    );
}

/**
 * @param list<StoredReplayRecord> $records
 * @return list<string>
 */
function summarizeOutcomeActions(array $records): array
{
    return array_map(
        static fn (StoredReplayRecord $record): string => $record->artifactName . '#' . $record->appendSequence,
        $records,
    );
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new LiveSuiteFailure($message);
    }
}

function utcNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function printUsage(): void
{
    fwrite(
        STDOUT,
        <<<TXT
Usage:
  php tools/live/run_live_suite.php
  php tools/live/run_live_suite.php --help

This is an opt-in live verification harness. It reads FreeSWITCH ESL credentials
from .env.live.local, captures a small real ESL exchange, persists the derived
artifacts, and exercises the current replay package surface against that data.

It is not part of the default PHPUnit or CI flow, and it must not print or
persist secrets from .env.live.local.

TXT
    );
}

/**
 * @return list<LiveArtifact>
 */
function captureLiveArtifacts(LiveEnv $env, string $connectionGeneration, string $sessionId, bool $includeBackgroundJob): array
{
    $client = LiveEslClient::connect($env);

    try {
        $artifacts = [];
        $capturePath = 'live.esl.inbound';
        $runtimeFlags = [
            'source' => 'tools/live/run_live_suite.php',
            'live' => true,
        ];

        if ($includeBackgroundJob) {
            $client->subscribeToBackgroundJobs();
        }

        $apiCommand = 'status';
        $artifacts[] = new LiveArtifact(
            artifactVersion: '1',
            artifactName: 'api.dispatch',
            captureTimestamp: utcNow(),
            capturePath: $capturePath,
            connectionGeneration: $connectionGeneration,
            sessionId: $sessionId,
            jobUuid: null,
            eventName: null,
            correlationIds: ['request_kind' => 'api', 'command' => $apiCommand],
            runtimeFlags: $runtimeFlags,
            payload: ['command' => $apiCommand],
        );

        $apiResponse = $client->sendApi($apiCommand);
        $artifacts[] = new LiveArtifact(
            artifactVersion: '1',
            artifactName: 'api.reply',
            captureTimestamp: utcNow(),
            capturePath: $capturePath,
            connectionGeneration: $connectionGeneration,
            sessionId: $sessionId,
            jobUuid: null,
            eventName: null,
            correlationIds: ['request_kind' => 'api', 'command' => $apiCommand],
            runtimeFlags: $runtimeFlags,
            payload: [
                'content_type' => $apiResponse->header('Content-Type'),
                'body' => trim($apiResponse->body),
            ],
        );

        if (!$includeBackgroundJob) {
            return $artifacts;
        }

        $bgapiCommand = 'status';
        $artifacts[] = new LiveArtifact(
            artifactVersion: '1',
            artifactName: 'bgapi.dispatch',
            captureTimestamp: utcNow(),
            capturePath: $capturePath,
            connectionGeneration: $connectionGeneration,
            sessionId: $sessionId,
            jobUuid: null,
            eventName: null,
            correlationIds: ['request_kind' => 'bgapi', 'command' => $bgapiCommand],
            runtimeFlags: $runtimeFlags,
            payload: ['command' => $bgapiCommand],
        );

        $bgapi = $client->sendBgapi($bgapiCommand);
        $jobUuid = $bgapi['jobUuid'];
        $artifacts[] = new LiveArtifact(
            artifactVersion: '1',
            artifactName: 'bgapi.ack',
            captureTimestamp: utcNow(),
            capturePath: $capturePath,
            connectionGeneration: $connectionGeneration,
            sessionId: $sessionId,
            jobUuid: $jobUuid,
            eventName: null,
            correlationIds: ['request_kind' => 'bgapi', 'command' => $bgapiCommand],
            runtimeFlags: $runtimeFlags,
            payload: [
                'reply_text' => $bgapi['ack']->header('Reply-Text'),
                'body' => trim($bgapi['ack']->body),
            ],
        );

        $backgroundJobFrame = $client->waitForBackgroundJob($jobUuid);
        $backgroundJobPayload = LiveEslClient::parsePlainEventBody($backgroundJobFrame->body);

        $artifacts[] = new LiveArtifact(
            artifactVersion: '1',
            artifactName: 'event.raw',
            captureTimestamp: utcNow(),
            capturePath: $capturePath,
            connectionGeneration: $connectionGeneration,
            sessionId: $sessionId,
            jobUuid: $jobUuid,
            eventName: $backgroundJobPayload['Event-Name'] ?? null,
            correlationIds: ['request_kind' => 'bgapi', 'command' => $bgapiCommand],
            runtimeFlags: $runtimeFlags,
            payload: $backgroundJobPayload,
        );

        return $artifacts;
    } finally {
        $client->close();
    }
}

/**
 * @param list<StoredReplayRecord> $records
 * @return list<StoredReplayRecord>
 */
function readAllRecords(
    Apntalk\EslReplay\Contracts\ReplayArtifactReaderInterface $reader,
    ?ReplayReadCriteria $criteria = null,
    int $limit = 2,
): array {
    $cursor = $reader->openCursor();
    $records = [];

    while (true) {
        $chunk = $reader->readFromCursor($cursor, $limit, $criteria);
        if ($chunk === []) {
            return $records;
        }

        foreach ($chunk as $record) {
            $records[] = $record;
            $cursor = new ReplayReadCursor(
                lastConsumedSequence: $record->appendSequence,
                byteOffsetHint: null,
            );
        }
    }
}

try {
    if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
        printUsage();
        exit(0);
    }

    $env = LiveEnv::load(dirname(__DIR__, 2) . '/.env.live.local');

    $root = sys_get_temp_dir() . '/esl-replay-live-' . bin2hex(random_bytes(6));
    $filesystemPath = $root . '/fs-store';
    $sqlitePath = $root . '/sqlite/replay.sqlite';
    $checkpointPath = $root . '/checkpoints';

    $filesystemConfig = new ReplayConfig(new StorageConfig($filesystemPath, StorageConfig::ADAPTER_FILESYSTEM));
    $sqliteConfig = new ReplayConfig(new StorageConfig($sqlitePath, StorageConfig::ADAPTER_SQLITE));

    $fsStore = ReplayArtifactStore::make($filesystemConfig);
    $sqliteStore = ReplayArtifactStore::make($sqliteConfig);

    $firstBatch = captureLiveArtifacts(
        env: $env,
        connectionGeneration: 'live-gen-1',
        sessionId: 'live-session-1',
        includeBackgroundJob: true,
    );

    foreach ($firstBatch as $artifact) {
        $fsStore->write($artifact);
        $sqliteStore->write($artifact);
    }

    $reopenedFsStore = ReplayArtifactStore::make($filesystemConfig);

    $secondBatch = captureLiveArtifacts(
        env: $env,
        connectionGeneration: 'live-gen-2',
        sessionId: 'live-session-2',
        includeBackgroundJob: false,
    );

    foreach ($secondBatch as $artifact) {
        $reopenedFsStore->write($artifact);
        $sqliteStore->write($artifact);
    }

    $allFsRecords = readAllRecords($reopenedFsStore, null, 3);
    $allSqliteRecords = readAllRecords($sqliteStore, null, 3);

    assertTrue(count($allFsRecords) >= 7, 'Expected at least seven live-derived stored records.');
    assertTrue(count($allFsRecords) === count($allSqliteRecords), 'Filesystem and SQLite record counts diverged.');
    assertTrue(
        summarizeRecords($allFsRecords) === summarizeRecords($allSqliteRecords),
        'Filesystem and SQLite stable record summaries diverged.',
    );

    $expectedSequences = range(1, count($allFsRecords));
    assertTrue(
        array_map(static fn (StoredReplayRecord $record): int => $record->appendSequence, $allFsRecords) === $expectedSequences,
        'Filesystem append ordering was not strictly sequential.',
    );

    $firstJobUuid = null;
    foreach ($allFsRecords as $record) {
        if ($record->jobUuid !== null) {
            $firstJobUuid = $record->jobUuid;
            break;
        }
    }

    assertTrue($firstJobUuid !== null, 'Expected a bgapi-derived Job-UUID in live-captured records.');

    $artifactFiltered = readAllRecords(
        $reopenedFsStore,
        new ReplayReadCriteria(artifactName: 'api.reply'),
        2,
    );
    assertTrue($artifactFiltered !== [], 'Artifact-name filtered live read returned no records.');
    assertTrue(
        array_unique(array_map(static fn (StoredReplayRecord $record): string => $record->artifactName, $artifactFiltered)) === ['api.reply'],
        'Artifact-name bounded read returned unexpected artifact types.',
    );

    $jobFiltered = readAllRecords(
        $reopenedFsStore,
        new ReplayReadCriteria(jobUuid: $firstJobUuid),
        2,
    );
    assertTrue(count($jobFiltered) >= 2, 'Job-UUID bounded read returned too few records.');
    assertTrue(
        array_unique(array_map(static fn (StoredReplayRecord $record): ?string => $record->jobUuid, $jobFiltered)) === [$firstJobUuid],
        'Job-UUID bounded read returned mismatched job identifiers.',
    );

    $sessionFiltered = readAllRecords(
        $reopenedFsStore,
        new ReplayReadCriteria(sessionId: 'live-session-2'),
        2,
    );
    assertTrue($sessionFiltered !== [], 'Session-id bounded read returned no records.');
    assertTrue(
        array_unique(array_map(static fn (StoredReplayRecord $record): ?string => $record->sessionId, $sessionFiltered)) === ['live-session-2'],
        'Session-id bounded read returned mismatched session identifiers.',
    );

    $generationFiltered = readAllRecords(
        $reopenedFsStore,
        new ReplayReadCriteria(connectionGeneration: 'live-gen-1'),
        2,
    );
    assertTrue($generationFiltered !== [], 'Connection-generation bounded read returned no records.');
    assertTrue(
        array_unique(array_map(static fn (StoredReplayRecord $record): ?string => $record->connectionGeneration, $generationFiltered)) === ['live-gen-1'],
        'Connection-generation bounded read returned mismatched generations.',
    );

    $capturedFrom = $secondBatch[0]->getCaptureTimestamp()->modify('-1 second');
    $capturedUntil = $secondBatch[count($secondBatch) - 1]->getCaptureTimestamp()->modify('+1 second');
    $windowFiltered = readAllRecords(
        $reopenedFsStore,
        new ReplayReadCriteria(capturedFrom: $capturedFrom, capturedUntil: $capturedUntil),
        2,
    );
    assertTrue($windowFiltered !== [], 'Time-window bounded read returned no records.');
    assertTrue(
        array_unique(array_map(static fn (StoredReplayRecord $record): ?string => $record->sessionId, $windowFiltered)) === ['live-session-2'],
        'Time-window bounded read did not isolate the second live capture batch.',
    );

    $checkpointStore = FilesystemCheckpointStore::make(
        new CheckpointConfig($checkpointPath, 'live-suite'),
    );
    $checkpointService = new ReplayCheckpointService($checkpointStore, 'live-suite');
    $resumeCursor = new ReplayReadCursor(
        lastConsumedSequence: $allFsRecords[2]->appendSequence,
        byteOffsetHint: null,
    );
    $checkpointService->save($resumeCursor, ['source' => 'live-suite']);
    $loadedCheckpoint = $checkpointService->load();
    assertTrue($loadedCheckpoint !== null, 'Checkpoint save/load over live-captured records failed.');
    assertTrue(
        $loadedCheckpoint->cursor->lastConsumedSequence === $resumeCursor->lastConsumedSequence,
        'Checkpoint resume cursor did not round-trip correctly.',
    );
    $resumedRecords = $reopenedFsStore->readFromCursor($loadedCheckpoint->cursor, 10);
    assertTrue($resumedRecords !== [], 'Checkpoint resume returned no remaining live-captured records.');
    assertTrue(
        $resumedRecords[0]->appendSequence === $resumeCursor->lastConsumedSequence + 1,
        'Checkpoint resume did not continue from the next append-sequence.',
    );
    $checkpointService->clear();
    assertTrue($checkpointService->load() === null, 'Checkpoint clear did not remove the saved checkpoint.');

    $dryRunExecutor = OfflineReplayExecutor::make(
        new ExecutionConfig(dryRun: true, batchLimit: count($allFsRecords)),
        $reopenedFsStore,
    );
    $dryRunPlan = $dryRunExecutor->plan($reopenedFsStore->openCursor());
    $dryRunResult = $dryRunExecutor->execute($dryRunPlan);
    assertTrue($dryRunResult->success, 'Dry-run replay over live-captured records failed.');
    assertTrue($dryRunResult->processedCount === 0, 'Dry-run replay processed live-captured records.');
    assertTrue($dryRunResult->skippedCount === count($allFsRecords), 'Dry-run replay skip count was incorrect.');

    $replyHandler = new FakeReplayRecordHandler('handled_reply');
    $eventHandler = new FakeReplayRecordHandler('handled_event');
    $handlerRegistry = new ReplayHandlerRegistry([
        'api.reply' => $replyHandler,
        'event.raw' => $eventHandler,
    ]);

    $observationalExecutor = OfflineReplayExecutor::make(
        new ExecutionConfig(dryRun: false, batchLimit: count($allFsRecords)),
        $reopenedFsStore,
        $handlerRegistry,
    );
    $observationalPlan = $observationalExecutor->plan($reopenedFsStore->openCursor());
    $observationalResult = $observationalExecutor->execute($observationalPlan);
    assertTrue($observationalResult->success, 'Observational replay over live-captured records failed.');
    assertTrue($replyHandler->handledSequences !== [], 'api.reply handler was not invoked for live-captured replay.');
    assertTrue($eventHandler->handledSequences !== [], 'event.raw handler was not invoked for live-captured replay.');

    $secondReplyHandler = new FakeReplayRecordHandler('handled_reply');
    $secondEventHandler = new FakeReplayRecordHandler('handled_event');
    $repeatExecutor = OfflineReplayExecutor::make(
        new ExecutionConfig(dryRun: false, batchLimit: count($allFsRecords)),
        $reopenedFsStore,
        new ReplayHandlerRegistry([
            'api.reply' => $secondReplyHandler,
            'event.raw' => $secondEventHandler,
        ]),
    );
    $repeatResult = $repeatExecutor->execute($repeatExecutor->plan($reopenedFsStore->openCursor()));
    assertTrue(
        array_map(static fn (array $outcome): string => (string) $outcome['action'], $observationalResult->outcomes)
            === array_map(static fn (array $outcome): string => (string) $outcome['action'], $repeatResult->outcomes),
        'Repeated observational replay did not preserve stable action ordering.',
    );

    $reinjectionDryRunInjector = new FakeReplayInjector();
    $reinjectionDryRunExecutor = OfflineReplayExecutor::make(
        new ExecutionConfig(
            dryRun: true,
            reinjectionEnabled: true,
            reinjectionArtifactAllowlist: ['api.dispatch'],
            batchLimit: count($allFsRecords),
        ),
        $reopenedFsStore,
        null,
        $reinjectionDryRunInjector,
    );
    $reinjectionDryRunResult = $reinjectionDryRunExecutor->execute(
        $reinjectionDryRunExecutor->plan($reopenedFsStore->openCursor()),
    );
    assertTrue($reinjectionDryRunResult->success, 'Dry-run guarded reinjection failed.');
    assertTrue($reinjectionDryRunInjector->injectedSequences === [], 'Dry-run guarded reinjection performed a live injection.');

    $reinjectionDisabledExecutor = OfflineReplayExecutor::make(
        new ExecutionConfig(dryRun: false, batchLimit: count($allFsRecords)),
        $reopenedFsStore,
    );
    $reinjectionDisabledResult = $reinjectionDisabledExecutor->execute(
        $reinjectionDisabledExecutor->plan($reopenedFsStore->openCursor()),
    );
    assertTrue($reinjectionDisabledResult->success, 'Disabled-by-default reinjection path did not stay observational.');
    assertTrue(
        !in_array('reinjected', array_map(static fn (array $outcome): string => (string) $outcome['action'], $reinjectionDisabledResult->outcomes), true),
        'Disabled-by-default executor unexpectedly performed reinjection.',
    );

    $reinjectionInjector = new FakeReplayInjector();
    $reinjectionExecutor = OfflineReplayExecutor::make(
        new ExecutionConfig(
            dryRun: false,
            reinjectionEnabled: true,
            reinjectionArtifactAllowlist: ['api.dispatch'],
            batchLimit: count($allFsRecords),
        ),
        $reopenedFsStore,
        null,
        $reinjectionInjector,
    );
    $reinjectionResult = $reinjectionExecutor->execute($reinjectionExecutor->plan($reopenedFsStore->openCursor()));
    assertTrue($reinjectionResult->success, 'Execute-mode guarded reinjection over live-captured records failed.');
    assertTrue($reinjectionInjector->injectedSequences !== [], 'Execute-mode guarded reinjection did not inject allowlisted api.dispatch artifacts.');
    assertTrue(
        in_array('reinjection_rejected', array_map(static fn (array $outcome): string => (string) $outcome['action'], $reinjectionResult->outcomes), true),
        'Execute-mode guarded reinjection did not reject observational live-captured artifacts.',
    );

    $summary = [
        'connection_smoke' => [
            'reachable' => true,
            'captured_batches' => 2,
        ],
        'capture_and_persistence' => [
            'filesystem_records' => count($allFsRecords),
            'sqlite_records' => count($allSqliteRecords),
            'job_uuid_present' => true,
        ],
        'bounded_reads' => [
            'artifact_name' => count($artifactFiltered),
            'job_uuid' => count($jobFiltered),
            'session_id' => count($sessionFiltered),
            'connection_generation' => count($generationFiltered),
            'time_window' => count($windowFiltered),
        ],
        'checkpoint' => [
            'round_trip' => true,
            'resumed_records' => count($resumedRecords),
        ],
        'offline_replay' => [
            'dry_run_skip_count' => $dryRunResult->skippedCount,
            'observational_processed_count' => $observationalResult->processedCount,
            'deterministic_actions' => array_map(
                static fn (array $outcome): string => (string) $outcome['action'],
                $observationalResult->outcomes,
            ),
        ],
        'guarded_reinjection' => [
            'dry_run_injected' => count($reinjectionDryRunInjector->injectedSequences),
            'execute_mode_injected' => count($reinjectionInjector->injectedSequences),
        ],
        'stable_record_summary' => summarizeRecords($allFsRecords),
    ];

    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'LIVE_SUITE_FAILURE: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
