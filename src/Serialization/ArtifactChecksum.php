<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Serialization;

use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Computes and verifies SHA-256 checksums over canonical artifact fields.
 *
 * The checksum is an integrity marker only. Consumers may invoke verify() to
 * check that the canonical artifact fields still match the stored checksum. It
 * does not participate in deduplication semantics, and ordinary read paths do
 * not verify it automatically.
 *
 * Canonical form: JSON-encoded, keys sorted, no escaped slashes, UTF-8.
 * The canonical form is stable across PHP versions and must not change
 * without a schema migration.
 */
final class ArtifactChecksum
{
    private const ALGORITHM = 'sha256';

    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    /**
     * Compute a checksum from a captured artifact envelope.
     * Called at write time before the record is persisted.
     */
    public static function compute(CapturedArtifactEnvelope $artifact): string
    {
        return self::computeFromFields(
            artifactVersion: $artifact->getArtifactVersion(),
            artifactName: $artifact->getArtifactName(),
            captureTimestamp: $artifact->getCaptureTimestamp()->format(\DateTimeInterface::RFC3339_EXTENDED),
            payload: $artifact->getPayload(),
        );
    }

    /**
     * Verify that a stored record's checksum matches its canonical artifact
     * fields. This is consumer-invoked; normal readers do not call it.
     */
    public static function verify(StoredReplayRecord $record): bool
    {
        $expected = self::computeFromFields(
            artifactVersion: $record->artifactVersion,
            artifactName: $record->artifactName,
            captureTimestamp: $record->captureTimestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
            payload: $record->payload,
        );

        return hash_equals($expected, $record->checksum);
    }

    /**
     * Compute from raw fields. Used internally by compute() and verify()
     * to ensure both use the exact same canonical representation.
     *
     * @param array<string, mixed> $payload
     */
    private static function computeFromFields(
        string $artifactVersion,
        string $artifactName,
        string $captureTimestamp,
        array $payload,
    ): string {
        // Recursively sort keys for determinism regardless of insertion order.
        $payload = self::sortKeysRecursive($payload);

        $canonical = [
            'artifact_name'    => $artifactName,
            'artifact_version' => $artifactVersion,
            'capture_timestamp' => $captureTimestamp,
            'payload'          => $payload,
        ];

        // Keys in $canonical are already in ASCII order (a < c < p).
        // json_encode preserves insertion order, so we rely on that.
        $json = json_encode($canonical, self::JSON_FLAGS);

        return hash(self::ALGORITHM, $json);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function sortKeysRecursive(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortKeysRecursive($value);
            }
        }
        return $data;
    }
}
