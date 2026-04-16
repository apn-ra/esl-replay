<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Adapter\Sqlite;

use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;
use Apntalk\EslReplay\Config\StorageConfig;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Exceptions\SerializationException;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\ArtifactPersistenceException;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use Apntalk\EslReplay\Storage\StoredReplayRecordFactory;

/**
 * SQLite-backed replay artifact store.
 *
 * Preserves the same append-order and reader semantics as the filesystem
 * adapter while using indexed SQL queries for bounded reads.
 */
final class SqliteReplayArtifactStore implements ReplayArtifactStoreInterface
{
    private readonly \PDO $pdo;
    private readonly StoredReplayRecordFactory $recordFactory;

    public function __construct(string $databasePath)
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new ArtifactPersistenceException(
                    "SqliteReplayArtifactStore: failed to create storage directory: {$directory}",
                );
            }
        }

        $this->pdo = new \PDO('sqlite:' . $databasePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->createSchema();
        $this->recordFactory = new StoredReplayRecordFactory($this->recoverLastSequence());
    }

    public static function fromStorageConfig(StorageConfig $config): self
    {
        return new self($config->storagePath);
    }

    public function write(CapturedArtifactEnvelope $artifact): ReplayRecordId
    {
        $record = $this->recordFactory->fromEnvelope($artifact);

        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO replay_records (
                id, artifact_version, artifact_name, capture_timestamp, stored_at,
                append_sequence, connection_generation, session_id, job_uuid, event_name,
                capture_path, correlation_ids, runtime_flags, payload, checksum, tags
            ) VALUES (
                :id, :artifact_version, :artifact_name, :capture_timestamp, :stored_at,
                :append_sequence, :connection_generation, :session_id, :job_uuid, :event_name,
                :capture_path, :correlation_ids, :runtime_flags, :payload, :checksum, :tags
            )
            SQL
        );

        $statement->execute($this->recordToRow($record));

        return $record->id;
    }

    public function readById(ReplayRecordId $id): ?StoredReplayRecord
    {
        $statement = $this->pdo->prepare('SELECT * FROM replay_records WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id->value]);
        $row = $statement->fetch();

        return is_array($row) ? $this->rowToRecord($row) : null;
    }

    /**
     * @return list<StoredReplayRecord>
     */
    public function readFromCursor(
        ReplayReadCursor $cursor,
        int $limit = 100,
        ?ReplayReadCriteria $criteria = null,
    ): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException('readFromCursor limit must be >= 1.');
        }

        $conditions = ['append_sequence > :last_consumed_sequence'];
        $parameters = [
            'last_consumed_sequence' => $cursor->lastConsumedSequence,
            'limit' => $limit,
        ];

        if ($criteria?->capturedFrom !== null) {
            $conditions[] = 'capture_timestamp >= :captured_from';
            $parameters['captured_from'] = $criteria->capturedFrom->format(\DateTimeInterface::RFC3339_EXTENDED);
        }

        if ($criteria?->capturedUntil !== null) {
            $conditions[] = 'capture_timestamp <= :captured_until';
            $parameters['captured_until'] = $criteria->capturedUntil->format(\DateTimeInterface::RFC3339_EXTENDED);
        }

        foreach ([
            'artifact_name' => $criteria?->artifactName,
            'job_uuid' => $criteria?->jobUuid,
            'session_id' => $criteria?->sessionId,
            'connection_generation' => $criteria?->connectionGeneration,
        ] as $column => $value) {
            if ($value === null) {
                continue;
            }

            $placeholder = $column;
            $conditions[] = "{$column} = :{$placeholder}";
            $parameters[$placeholder] = $value;
        }

        $sql = sprintf(
            'SELECT * FROM replay_records WHERE %s ORDER BY append_sequence ASC LIMIT :limit',
            implode(' AND ', $conditions),
        );

        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $statement->bindValue(':' . $name, $value, $type);
        }
        $statement->execute();

        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        return array_map(fn (array $row): StoredReplayRecord => $this->rowToRecord($row), $rows);
    }

    public function openCursor(): ReplayReadCursor
    {
        return ReplayReadCursor::start();
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS replay_records (
                id TEXT PRIMARY KEY,
                artifact_version TEXT NOT NULL,
                artifact_name TEXT NOT NULL,
                capture_timestamp TEXT NOT NULL,
                stored_at TEXT NOT NULL,
                append_sequence INTEGER NOT NULL UNIQUE,
                connection_generation TEXT NULL,
                session_id TEXT NULL,
                job_uuid TEXT NULL,
                event_name TEXT NULL,
                capture_path TEXT NULL,
                correlation_ids TEXT NOT NULL,
                runtime_flags TEXT NOT NULL,
                payload TEXT NOT NULL,
                checksum TEXT NOT NULL,
                tags TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS replay_records_append_sequence_idx
                ON replay_records (append_sequence);
            CREATE INDEX IF NOT EXISTS replay_records_capture_timestamp_idx
                ON replay_records (capture_timestamp);
            CREATE INDEX IF NOT EXISTS replay_records_artifact_name_idx
                ON replay_records (artifact_name);
            CREATE INDEX IF NOT EXISTS replay_records_job_uuid_idx
                ON replay_records (job_uuid);
            CREATE INDEX IF NOT EXISTS replay_records_session_id_idx
                ON replay_records (session_id);
            CREATE INDEX IF NOT EXISTS replay_records_connection_generation_idx
                ON replay_records (connection_generation);
            SQL
        );
    }

    private function recoverLastSequence(): int
    {
        $result = $this->pdo->query('SELECT COALESCE(MAX(append_sequence), 0) AS max_sequence FROM replay_records');
        $row = $result !== false ? $result->fetch() : false;

        return is_array($row) ? (int) ($row['max_sequence'] ?? 0) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordToRow(StoredReplayRecord $record): array
    {
        return [
            'id' => $record->id->value,
            'artifact_version' => $record->artifactVersion,
            'artifact_name' => $record->artifactName,
            'capture_timestamp' => $record->captureTimestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
            'stored_at' => $record->storedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'append_sequence' => $record->appendSequence,
            'connection_generation' => $record->connectionGeneration,
            'session_id' => $record->sessionId,
            'job_uuid' => $record->jobUuid,
            'event_name' => $record->eventName,
            'capture_path' => $record->capturePath,
            'correlation_ids' => json_encode($record->correlationIds, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'runtime_flags' => json_encode($record->runtimeFlags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'payload' => json_encode($record->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'checksum' => $record->checksum,
            'tags' => json_encode($record->tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToRecord(array $row): StoredReplayRecord
    {
        try {
            return new StoredReplayRecord(
                id: new ReplayRecordId((string) $row['id']),
                artifactVersion: (string) $row['artifact_version'],
                artifactName: (string) $row['artifact_name'],
                captureTimestamp: new \DateTimeImmutable((string) $row['capture_timestamp']),
                storedAt: new \DateTimeImmutable((string) $row['stored_at']),
                appendSequence: (int) $row['append_sequence'],
                connectionGeneration: isset($row['connection_generation']) ? (string) $row['connection_generation'] : null,
                sessionId: isset($row['session_id']) ? (string) $row['session_id'] : null,
                jobUuid: isset($row['job_uuid']) ? (string) $row['job_uuid'] : null,
                eventName: isset($row['event_name']) ? (string) $row['event_name'] : null,
                capturePath: isset($row['capture_path']) ? (string) $row['capture_path'] : null,
                correlationIds: $this->decodeJsonObject((string) $row['correlation_ids']),
                runtimeFlags: $this->decodeJsonObject((string) $row['runtime_flags']),
                payload: $this->decodeJsonObject((string) $row['payload']),
                checksum: (string) $row['checksum'],
                tags: $this->decodeJsonObject((string) $row['tags']),
            );
        } catch (\Throwable $e) {
            throw new SerializationException(
                "SqliteReplayArtifactStore: failed to reconstruct stored replay record: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}
