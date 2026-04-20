<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Adapter\Filesystem;

use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\ArtifactPersistenceException;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Serialization\ReplayArtifactSerializer;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Filesystem-backed append-only replay artifact store.
 *
 * Artifacts are stored in a single NDJSON file:
 *   {storagePath}/artifacts.ndjson
 *
 * On construction the file is scanned to recover the current append sequence,
 * ensuring that a restarted store continues from the correct position.
 *
 * Concurrency: one package filesystem writer owns a storage path at a time
 * through artifacts.ndjson.writer.lock. Multiple readers may inspect the
 * stream while that owner is active. For broader concurrent writes use a
 * database adapter.
 *
 * Restart-safety: the store recovers the last committed sequence by scanning
 * the artifact file. Partial writes (e.g. from a crash mid-line) are skipped
 * by the deserializer, which reads only complete well-formed JSON lines.
 *
 * Internal — not part of the stable public API.
 * Obtain via ReplayArtifactStore::make(ReplayConfig $config).
 */
final class FilesystemReplayArtifactStore implements ReplayArtifactStoreInterface
{
    private const ARTIFACT_FILE = 'artifacts.ndjson';

    private readonly NdjsonReplayWriter $writer;
    private readonly NdjsonReplayReader $reader;
    private readonly string $filePath;

    /**
     * @throws ArtifactPersistenceException if the storage directory cannot be created
     */
    public function __construct(string $storagePath)
    {
        if (!is_dir($storagePath)) {
            if (!mkdir($storagePath, 0755, true) && !is_dir($storagePath)) {
                throw new ArtifactPersistenceException(
                    "FilesystemReplayArtifactStore: failed to create storage directory: {$storagePath}",
                );
            }
        }

        $this->filePath = rtrim($storagePath, '/\\') . '/' . self::ARTIFACT_FILE;

        $initialSequence = $this->recoverLastSequence();

        $this->writer = new NdjsonReplayWriter($this->filePath, $initialSequence);
        $this->reader = new NdjsonReplayReader($this->filePath);
    }

    public function write(CapturedArtifactEnvelope $artifact): ReplayRecordId
    {
        return $this->writer->write($artifact);
    }

    public function readById(ReplayRecordId $id): ?StoredReplayRecord
    {
        return $this->reader->readById($id);
    }

    /**
     * @return list<StoredReplayRecord>
     */
    public function readFromCursor(
        ReplayReadCursor $cursor,
        int $limit = 100,
        ?ReplayReadCriteria $criteria = null,
    ): array
    {
        return $this->reader->readFromCursor($cursor, $limit, $criteria);
    }

    public function openCursor(): ReplayReadCursor
    {
        return $this->reader->openCursor();
    }

    /**
     * Scan the artifact file to recover the highest appendSequence.
     * Returns 0 if the file is absent or empty.
     *
     * @throws ArtifactPersistenceException when an existing artifact file cannot be opened
     *
     * Since the writer guarantees append-order, the maximum sequence is on the
     * last valid line. We scan all lines and track the max to handle the edge
     * case of a partial-write corruption on the final line.
     */
    private function recoverLastSequence(): int
    {
        if (!file_exists($this->filePath)) {
            return 0;
        }

        $handle = @fopen($this->filePath, 'r');
        if ($handle === false) {
            throw new ArtifactPersistenceException(
                "FilesystemReplayArtifactStore: failed to open existing artifact file for sequence recovery: {$this->filePath}",
            );
        }

        $serializer  = new ReplayArtifactSerializer();
        $maxSequence = 0;

        try {
            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line === false || trim($line) === '') {
                    continue;
                }
                try {
                    $record = $serializer->deserialize($line);
                    if ($record->appendSequence > $maxSequence) {
                        $maxSequence = $record->appendSequence;
                    }
                } catch (\Exception) {
                    // Skip malformed lines (e.g. partial write from a prior crash)
                    continue;
                }
            }
        } finally {
            fclose($handle);
        }

        return $maxSequence;
    }
}
