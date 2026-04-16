<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Adapter\Filesystem;

use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;
use Apntalk\EslReplay\Contracts\ReplayArtifactWriterInterface;
use Apntalk\EslReplay\Exceptions\ArtifactPersistenceException;
use Apntalk\EslReplay\Serialization\ReplayArtifactSerializer;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecordFactory;

/**
 * Append-only NDJSON writer for replay artifacts.
 *
 * Each artifact is serialized as a single-line JSON string followed by "\n".
 * File-level exclusive locking (LOCK_EX) prevents concurrent write corruption
 * within a single process. Cross-process safety requires OS-level flock support.
 *
 * Ordering guarantee: records are appended in the order write() is called.
 * The appendSequence is strictly monotonically increasing within this writer instance.
 *
 * Assumption: one NdjsonReplayWriter instance owns one NDJSON file.
 * For multi-process scenarios use a database adapter instead.
 *
 * Internal — not part of the stable public API.
 */
final class NdjsonReplayWriter implements ReplayArtifactWriterInterface
{
    private readonly StoredReplayRecordFactory $factory;
    private readonly ReplayArtifactSerializer $serializer;

    /**
     * @param string $filePath       Absolute path to the NDJSON artifact file.
     * @param int    $initialSequence The sequence number of the last record already in
     *                               the stream. Pass 0 for a new stream. Pass N to
     *                               continue after N existing records.
     */
    public function __construct(
        private readonly string $filePath,
        int $initialSequence = 0,
    ) {
        $this->factory    = new StoredReplayRecordFactory($initialSequence);
        $this->serializer = new ReplayArtifactSerializer();
    }

    /**
     * Persist a captured artifact to the NDJSON file and return its storage record id.
     *
     * @throws ArtifactPersistenceException on any I/O failure
     */
    public function write(CapturedArtifactEnvelope $artifact): ReplayRecordId
    {
        $record = $this->factory->fromEnvelope($artifact);
        $line   = $this->serializer->serialize($record) . "\n";

        $handle = @fopen($this->filePath, 'a');
        if ($handle === false) {
            throw new ArtifactPersistenceException(
                "NdjsonReplayWriter: failed to open artifact file for writing: {$this->filePath}",
            );
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new ArtifactPersistenceException(
                    "NdjsonReplayWriter: failed to acquire exclusive lock on: {$this->filePath}",
                );
            }

            $byteCount = fwrite($handle, $line);
            if ($byteCount === false || $byteCount !== strlen($line)) {
                throw new ArtifactPersistenceException(
                    "NdjsonReplayWriter: incomplete write to: {$this->filePath}",
                );
            }

            if (!fflush($handle)) {
                throw new ArtifactPersistenceException(
                    "NdjsonReplayWriter: failed to flush artifact record to: {$this->filePath}",
                );
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $record->id;
    }

    /**
     * The current sequence position of this writer.
     * Reflects how many records have been written since construction.
     */
    public function currentSequence(): int
    {
        return $this->factory->currentSequence();
    }
}
