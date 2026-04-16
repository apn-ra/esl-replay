<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Serialization;

use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\SerializationException;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Deterministic serialization for stored replay records.
 *
 * Serializes StoredReplayRecord to a single-line JSON string suitable
 * for NDJSON storage. Deserializes stored JSON lines back to records.
 *
 * Determinism guarantee:
 * - Identical StoredReplayRecord inputs always produce identical JSON output.
 * - Key order in the serialized envelope is fixed and must not change without
 *   a schema migration.
 * - JSON_UNESCAPED_SLASHES and JSON_UNESCAPED_UNICODE are always set.
 *
 * Schema version: The serialized envelope includes a "schema_version" field
 * so that future readers can detect and handle format changes explicitly.
 *
 * Internal — not part of the stable public API.
 */
final class ReplayArtifactSerializer
{
    /**
     * Current schema version written into every serialized record.
     * Readers that encounter an unknown schema_version must fail explicitly.
     */
    public const SCHEMA_VERSION = 1;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    /**
     * Serialize a stored replay record to a JSON string (no trailing newline).
     * The caller is responsible for appending "\n" for NDJSON output.
     */
    public function serialize(StoredReplayRecord $record): string
    {
        try {
            return json_encode($this->toArray($record), self::JSON_FLAGS);
        } catch (\JsonException $e) {
            throw new SerializationException(
                "Failed to serialize record {$record->id}: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * Deserialize a single JSON line back to a StoredReplayRecord.
     *
     * @throws SerializationException on malformed input or unsupported schema version
     */
    public function deserialize(string $json): StoredReplayRecord
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SerializationException(
                "Failed to deserialize replay record: invalid JSON. {$e->getMessage()}",
                previous: $e,
            );
        }

        if (!is_array($data)) {
            throw new SerializationException('Deserialized value is not an object.');
        }

        $schemaVersion = $data['schema_version'] ?? null;
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new SerializationException(
                "Unsupported schema_version: {$schemaVersion}. "
                . 'Expected ' . self::SCHEMA_VERSION . '. '
                . 'This record was written by a different version of apntalk/esl-replay.'
            );
        }

        return $this->fromArray($data);
    }

    /**
     * Convert a StoredReplayRecord to an ordered associative array.
     * Key order here is the canonical serialized order and must not change.
     *
     * @return array<string, mixed>
     */
    private function toArray(StoredReplayRecord $record): array
    {
        return [
            'schema_version'       => self::SCHEMA_VERSION,
            'id'                   => $record->id->value,
            'artifact_version'     => $record->artifactVersion,
            'artifact_name'        => $record->artifactName,
            'capture_timestamp'    => $record->captureTimestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
            'stored_at'            => $record->storedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'append_sequence'      => $record->appendSequence,
            'connection_generation' => $record->connectionGeneration,
            'session_id'           => $record->sessionId,
            'job_uuid'             => $record->jobUuid,
            'event_name'           => $record->eventName,
            'capture_path'         => $record->capturePath,
            'correlation_ids'      => $record->correlationIds,
            'runtime_flags'        => $record->runtimeFlags,
            'payload'              => $record->payload,
            'checksum'             => $record->checksum,
            'tags'                 => $record->tags,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fromArray(array $data): StoredReplayRecord
    {
        try {
            return new StoredReplayRecord(
                id: new ReplayRecordId($this->requireString($data, 'id')),
                artifactVersion: $this->requireString($data, 'artifact_version'),
                artifactName: $this->requireString($data, 'artifact_name'),
                captureTimestamp: new \DateTimeImmutable($this->requireString($data, 'capture_timestamp')),
                storedAt: new \DateTimeImmutable($this->requireString($data, 'stored_at')),
                appendSequence: $this->requireInt($data, 'append_sequence'),
                connectionGeneration: isset($data['connection_generation']) && is_string($data['connection_generation'])
                    ? $data['connection_generation']
                    : null,
                sessionId: isset($data['session_id']) && is_string($data['session_id'])
                    ? $data['session_id']
                    : null,
                jobUuid: isset($data['job_uuid']) && is_string($data['job_uuid'])
                    ? $data['job_uuid']
                    : null,
                eventName: isset($data['event_name']) && is_string($data['event_name'])
                    ? $data['event_name']
                    : null,
                capturePath: isset($data['capture_path']) && is_string($data['capture_path'])
                    ? $data['capture_path']
                    : null,
                correlationIds: is_array($data['correlation_ids'] ?? null) ? $data['correlation_ids'] : [],
                runtimeFlags: is_array($data['runtime_flags'] ?? null) ? $data['runtime_flags'] : [],
                payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
                checksum: $this->requireString($data, 'checksum'),
                tags: is_array($data['tags'] ?? null) ? $data['tags'] : [],
            );
        } catch (\InvalidArgumentException|\Exception $e) {
            throw new SerializationException(
                "Failed to reconstruct StoredReplayRecord from stored data: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireString(array $data, string $key): string
    {
        if (!array_key_exists($key, $data) || !is_string($data[$key])) {
            throw new SerializationException("Missing or non-string field: {$key}");
        }
        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireInt(array $data, string $key): int
    {
        if (!array_key_exists($key, $data) || !is_int($data[$key])) {
            throw new SerializationException("Missing or non-integer field: {$key}");
        }
        return $data[$key];
    }
}
