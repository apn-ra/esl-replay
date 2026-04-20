<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Checkpoint;

use Apntalk\EslReplay\Artifact\OperatorIdentityKeys;
use Apntalk\EslReplay\Config\CheckpointConfig;
use Apntalk\EslReplay\Contracts\ReplayCheckpointInspectorInterface;
use Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\CheckpointException;

/**
 * Filesystem-backed checkpoint store.
 *
 * Each checkpoint is persisted as a JSON file:
 *   {storagePath}/{sanitizedKeyPrefix}-{sha256(originalKey)}.checkpoint.json
 *
 * Writes are atomic: the JSON is written to a temp file, then renamed over the
 * target path. On POSIX systems rename(2) is atomic; on Windows it is
 * best-effort (PHP's rename is not atomic on Windows when the target exists).
 *
 * IMPORTANT: Checkpoints restore artifact-processing progress only.
 * They do NOT restore live FreeSWITCH sockets or ESL session state.
 *
 * Internal — not part of the stable public API.
 * Obtain via FilesystemCheckpointStore::make(CheckpointConfig $config).
 */
final class FilesystemCheckpointStore implements ReplayCheckpointStoreInterface, ReplayCheckpointInspectorInterface
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRETTY_PRINT;

    private const FILE_SUFFIX = '.checkpoint.json';
    private const SAFE_KEY_PREFIX_MAX_LENGTH = 80;

    /**
     * @throws CheckpointException if the storage directory cannot be created
     */
    public function __construct(private readonly string $storagePath)
    {
        if (!is_dir($storagePath)) {
            if (!mkdir($storagePath, 0755, true) && !is_dir($storagePath)) {
                throw new CheckpointException(
                    "FilesystemCheckpointStore: failed to create checkpoint directory: {$storagePath}",
                );
            }
        }
    }

    /**
     * Factory method: create a store from a CheckpointConfig.
     */
    public static function make(CheckpointConfig $config): self
    {
        return new self($config->storagePath);
    }

    /**
     * Persist a checkpoint. Atomically overwrites any existing checkpoint with the same key.
     *
     * @throws CheckpointException on I/O failure
     */
    public function save(ReplayCheckpoint $checkpoint): void
    {
        $data = [
            'key'                    => $checkpoint->key,
            'last_consumed_sequence' => $checkpoint->cursor->lastConsumedSequence,
            'byte_offset_hint'       => $checkpoint->cursor->byteOffsetHint,
            'saved_at'               => $checkpoint->savedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'metadata'               => $checkpoint->metadata,
        ];

        try {
            $json = json_encode($data, self::JSON_FLAGS);
        } catch (\JsonException $e) {
            throw new CheckpointException(
                "FilesystemCheckpointStore: failed to encode checkpoint '{$checkpoint->key}': {$e->getMessage()}",
                previous: $e,
            );
        }

        $filePath = $this->checkpointPath($checkpoint->key);
        $tmpPath  = $filePath . '.tmp';

        $written = @file_put_contents($tmpPath, $json, LOCK_EX);
        if ($written === false) {
            throw new CheckpointException(
                "FilesystemCheckpointStore: failed to write checkpoint to temp file: {$tmpPath}",
            );
        }

        if (!@rename($tmpPath, $filePath)) {
            @unlink($tmpPath);
            throw new CheckpointException(
                "FilesystemCheckpointStore: failed to atomically save checkpoint to: {$filePath}",
            );
        }
    }

    /**
     * Load a checkpoint by key. Returns null when no checkpoint exists for the key.
     *
     * @throws CheckpointException on I/O failure or corrupt data
     */
    public function load(string $key): ?ReplayCheckpoint
    {
        foreach ($this->checkpointLookupPaths($key) as $filePath) {
            $checkpoint = $this->loadCheckpointFile($filePath, $key);
            if ($checkpoint !== null) {
                return $checkpoint;
            }
        }

        return null;
    }

    public function exists(string $key): bool
    {
        foreach ($this->checkpointLookupPaths($key) as $filePath) {
            if (file_exists($filePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete the checkpoint for the given key. No-op if it does not exist.
     */
    public function delete(string $key): void
    {
        foreach ($this->checkpointLookupPaths($key) as $filePath) {
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }

    /**
     * @return list<ReplayCheckpoint>
     */
    public function find(ReplayCheckpointCriteria $criteria): array
    {
        $pattern = rtrim($this->storagePath, '/\\') . '/*' . self::FILE_SUFFIX;
        $files = glob($pattern);
        if ($files === false || $files === []) {
            return [];
        }

        sort($files, SORT_STRING);

        $matches = [];

        foreach ($files as $file) {
            $checkpoint = $this->loadCheckpointFile($file);
            if ($checkpoint === null || !$this->matchesCriteria($checkpoint, $criteria)) {
                continue;
            }

            $matches[] = $checkpoint;
        }

        usort($matches, static function (ReplayCheckpoint $left, ReplayCheckpoint $right): int {
            $savedAtComparison = $right->savedAt <=> $left->savedAt;
            if ($savedAtComparison !== 0) {
                return $savedAtComparison;
            }

            return $left->key <=> $right->key;
        });

        return array_slice($matches, 0, $criteria->limit);
    }

    /**
     * Resolve the filesystem path for a checkpoint key.
     *
     * Keys keep a sanitised readable prefix plus a SHA-256 hash of the original
     * key, so distinct keys that sanitise to the same prefix cannot collide.
     */
    private function checkpointPath(string $key): string
    {
        return rtrim($this->storagePath, '/\\') . '/'
            . $this->safeCheckpointFilenamePrefix($key)
            . '-'
            . hash('sha256', $key)
            . self::FILE_SUFFIX;
    }

    /**
     * @return non-empty-list<string>
     */
    private function checkpointLookupPaths(string $key): array
    {
        return [
            $this->checkpointPath($key),
            $this->legacyCheckpointPath($key),
        ];
    }

    private function legacyCheckpointPath(string $key): string
    {
        return rtrim($this->storagePath, '/\\') . '/' . $this->legacySafeCheckpointKey($key) . self::FILE_SUFFIX;
    }

    private function safeCheckpointFilenamePrefix(string $key): string
    {
        $safe = $this->sanitizeCheckpointKey($key);
        if ($safe === '' || $safe === '_') {
            $safe = 'checkpoint';
        }

        return substr($safe, 0, self::SAFE_KEY_PREFIX_MAX_LENGTH);
    }

    private function legacySafeCheckpointKey(string $key): string
    {
        $safe = $this->sanitizeCheckpointKey($key);

        if ($safe === '' || $safe === '_') {
            $safe = md5($key);
        }

        return $safe;
    }

    private function sanitizeCheckpointKey(string $key): string
    {
        // Allow: a-z A-Z 0-9 hyphen underscore dot
        $safe = (string) preg_replace('/[^a-zA-Z0-9\-_.]++/', '_', $key);

        return trim($safe, '.');
    }

    private function loadCheckpointFile(string $filePath, ?string $fallbackKey = null): ?ReplayCheckpoint
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $json = @file_get_contents($filePath);
        if ($json === false) {
            throw new CheckpointException(
                "FilesystemCheckpointStore: failed to read checkpoint file: {$filePath}",
            );
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CheckpointException(
                "FilesystemCheckpointStore: checkpoint file is corrupt or invalid JSON: {$filePath}",
                previous: $e,
            );
        }

        if (!is_array($data)) {
            throw new CheckpointException(
                "FilesystemCheckpointStore: checkpoint file does not contain a JSON object: {$filePath}",
            );
        }

        try {
            return new ReplayCheckpoint(
                key: (string) ($data['key'] ?? $fallbackKey ?? basename($filePath, self::FILE_SUFFIX)),
                cursor: new ReplayReadCursor(
                    lastConsumedSequence: (int) ($data['last_consumed_sequence'] ?? 0),
                    byteOffsetHint: isset($data['byte_offset_hint']) && is_int($data['byte_offset_hint'])
                        ? $data['byte_offset_hint']
                        : null,
                ),
                savedAt: new \DateTimeImmutable((string) ($data['saved_at'] ?? 'now')),
                metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            );
        } catch (\Exception $e) {
            throw new CheckpointException(
                "FilesystemCheckpointStore: failed to reconstruct checkpoint from: {$filePath} — {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    private function matchesCriteria(ReplayCheckpoint $checkpoint, ReplayCheckpointCriteria $criteria): bool
    {
        if (
            $criteria->replaySessionId !== null
            && ($checkpoint->metadata[OperatorIdentityKeys::REPLAY_SESSION_ID] ?? null) !== $criteria->replaySessionId
        ) {
            return false;
        }

        if ($criteria->jobUuid !== null && ($checkpoint->metadata['job_uuid'] ?? null) !== $criteria->jobUuid) {
            return false;
        }

        if (
            $criteria->pbxNodeSlug !== null
            && ($checkpoint->metadata[OperatorIdentityKeys::PBX_NODE_SLUG] ?? null) !== $criteria->pbxNodeSlug
        ) {
            return false;
        }

        if (
            $criteria->workerSessionId !== null
            && ($checkpoint->metadata[OperatorIdentityKeys::WORKER_SESSION_ID] ?? null) !== $criteria->workerSessionId
        ) {
            return false;
        }

        return true;
    }
}
