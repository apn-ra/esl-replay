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
 * A sibling coordination lock ({artifact file}.lock) is acquired before opening
 * the artifact file, so package writers serialize with filesystem retention
 * rewrite/rename operations and do not append to orphaned inodes.
 * A separate long-lived ownership lock ({artifact file}.writer.lock) prevents
 * multiple package writer instances from owning the same sequence stream.
 *
 * Ordering guarantee: records are appended in the order write() is called.
 * The appendSequence is strictly monotonically increasing within this writer instance.
 *
 * Assumption: one package writer owns one NDJSON file at a time.
 * For multi-process scenarios use a database adapter instead.
 *
 * Internal — not part of the stable public API.
 */
final class NdjsonReplayWriter implements ReplayArtifactWriterInterface
{
    private readonly StoredReplayRecordFactory $factory;
    private readonly ReplayArtifactSerializer $serializer;
    private readonly string $lockFilePath;
    private readonly FilesystemWriterOwnershipLock $ownershipLock;

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
        $this->ownershipLock = new FilesystemWriterOwnershipLock($filePath . '.writer.lock');
        $this->factory    = new StoredReplayRecordFactory($initialSequence);
        $this->serializer = new ReplayArtifactSerializer();
        $this->lockFilePath = $filePath . '.lock';
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

        $lockHandle = @fopen($this->lockFilePath, 'c');
        if ($lockHandle === false) {
            throw new ArtifactPersistenceException(
                "NdjsonReplayWriter: failed to open artifact coordination lock: {$this->lockFilePath}",
            );
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new ArtifactPersistenceException(
                    "NdjsonReplayWriter: failed to acquire exclusive coordination lock: {$this->lockFilePath}",
                );
            }

            $handle = @fopen($this->filePath, 'a');
            if ($handle === false) {
                throw new ArtifactPersistenceException(
                    "NdjsonReplayWriter: failed to open artifact file for writing: {$this->filePath}",
                );
            }

            try {
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
                fclose($handle);
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
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

    public function __destruct()
    {
        $this->ownershipLock->release();
    }
}
