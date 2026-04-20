<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Retention;

use Apntalk\EslReplay\Adapter\Filesystem\NdjsonReplayReader;
use Apntalk\EslReplay\Checkpoint\CheckpointCompatibilityValidator;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria;
use Apntalk\EslReplay\Contracts\ReplayCheckpointInspectorInterface;
use Apntalk\EslReplay\Exceptions\RetentionException;
use Apntalk\EslReplay\Exceptions\SerializationException;
use Apntalk\EslReplay\Serialization\ReplayArtifactSerializer;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Explicit filesystem retention coordinator.
 *
 * Pruning is conservative:
 * - only a prefix of the ordered stream is removed
 * - active checkpoints are validated before pruning
 * - records beyond the oldest active checkpoint cursor are preserved
 * - a protected tail window may be reserved from pruning
 * - rewrite/rename is coordinated with package filesystem writers via a
 *   sibling artifact lock and fails closed if that lock is already held
 */
final class CheckpointAwarePruner
{
    private const ARTIFACT_FILE = 'artifacts.ndjson';

    private readonly ReplayArtifactSerializer $serializer;
    private readonly CheckpointCompatibilityValidator $validator;
    private readonly string $artifactFilePath;
    private readonly string $lockFilePath;

    public function __construct(
        string $storagePath,
        ?CheckpointCompatibilityValidator $validator = null,
    ) {
        $this->artifactFilePath = rtrim($storagePath, '/\\') . '/' . self::ARTIFACT_FILE;
        $this->lockFilePath = $this->artifactFilePath . '.lock';
        $this->serializer = new ReplayArtifactSerializer();
        $this->validator = $validator ?? new CheckpointCompatibilityValidator();
    }

    /**
     * @param list<ReplayCheckpoint> $activeCheckpoints
     *
     * @throws RetentionException
     */
    public function plan(
        array $activeCheckpoints,
        PrunePolicy $policy,
        ?\DateTimeImmutable $now = null,
    ): RetentionPlan {
        $entries = $this->loadEntries();
        $records = array_map(
            static fn (array $entry): StoredReplayRecord => $entry['record'],
            $entries,
        );

        $this->validator->assertCompatible(
            new NdjsonReplayReader($this->artifactFilePath),
            $activeCheckpoints,
        );

        $streamBytesBefore = array_sum(array_map(static fn (array $entry): int => $entry['bytes'], $entries));
        if ($entries === []) {
            return new RetentionPlan(
                streamBytesBefore: 0,
                streamBytesAfter: 0,
                prunedCount: 0,
                retainedCount: 0,
                prunedSequences: [],
                retainedFirstSequence: null,
                retainedLastSequence: null,
                checkpointFloorSequence: null,
                protectedWindowStartSequence: null,
                sizeTargetSatisfied: true,
            );
        }

        $lastSequence = $records[array_key_last($records)]->appendSequence;
        $checkpointFloor = $activeCheckpoints === []
            ? $lastSequence
            : min(array_map(
                static fn (ReplayCheckpoint $checkpoint): int => $checkpoint->cursor->lastConsumedSequence,
                $activeCheckpoints,
            ));
        $protectedWindowStart = $policy->protectedRecordCount > 0
            ? max(1, $lastSequence - $policy->protectedRecordCount + 1)
            : null;
        $maxPrunableSequence = $protectedWindowStart !== null
            ? min($checkpointFloor, $protectedWindowStart - 1)
            : $checkpointFloor;

        $prunedCount = 0;
        $bytesToRemove = 0;
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ageCutoff = $policy->maxRecordAge !== null ? $now->sub($policy->maxRecordAge) : null;

        foreach ($entries as $entry) {
            $record = $entry['record'];
            if ($record->appendSequence > $maxPrunableSequence) {
                break;
            }

            if ($ageCutoff !== null && $record->captureTimestamp > $ageCutoff) {
                break;
            }

            $prunedCount++;
            $bytesToRemove += $entry['bytes'];
        }

        $remainingBytes = $streamBytesBefore - $bytesToRemove;

        if ($policy->maxStreamBytes !== null && $remainingBytes > $policy->maxStreamBytes) {
            for ($index = $prunedCount; $index < count($entries); $index++) {
                $record = $entries[$index]['record'];
                if ($record->appendSequence > $maxPrunableSequence) {
                    break;
                }

                $prunedCount++;
                $bytesToRemove += $entries[$index]['bytes'];
                $remainingBytes = $streamBytesBefore - $bytesToRemove;

                if ($remainingBytes <= $policy->maxStreamBytes) {
                    break;
                }
            }
        }

        $prunedEntries = array_slice($entries, 0, $prunedCount);
        $retainedEntries = array_slice($entries, $prunedCount);

        return new RetentionPlan(
            streamBytesBefore: $streamBytesBefore,
            streamBytesAfter: max(0, $streamBytesBefore - $bytesToRemove),
            prunedCount: count($prunedEntries),
            retainedCount: count($retainedEntries),
            prunedSequences: array_map(
                static fn (array $entry): int => $entry['record']->appendSequence,
                $prunedEntries,
            ),
            retainedFirstSequence: $retainedEntries !== [] ? $retainedEntries[0]['record']->appendSequence : null,
            retainedLastSequence: $retainedEntries !== [] ? $retainedEntries[array_key_last($retainedEntries)]['record']->appendSequence : null,
            checkpointFloorSequence: $activeCheckpoints === [] ? null : $checkpointFloor,
            protectedWindowStartSequence: $protectedWindowStart,
            sizeTargetSatisfied: $policy->maxStreamBytes === null
                || max(0, $streamBytesBefore - $bytesToRemove) <= $policy->maxStreamBytes,
        );
    }

    /**
     * @param list<ReplayCheckpoint> $activeCheckpoints
     *
     * @throws RetentionException
     */
    public function prune(
        array $activeCheckpoints,
        PrunePolicy $policy,
        ?\DateTimeImmutable $now = null,
    ): RetentionResult {
        return $this->withExclusiveRewriteLock(function () use ($activeCheckpoints, $policy, $now): RetentionResult {
            $plan = $this->plan($activeCheckpoints, $policy, $now);
            $entries = $this->loadEntries();
            $retainedEntries = array_slice($entries, $plan->prunedCount);
            $prunedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if ($plan->prunedCount === 0) {
                return new RetentionResult(plan: $plan, changed: false, prunedAt: $prunedAt);
            }

            $this->rewriteRetainedEntries($retainedEntries);

            return new RetentionResult(plan: $plan, changed: true, prunedAt: $prunedAt);
        });
    }

    public function planForCheckpointQuery(
        ReplayCheckpointInspectorInterface $checkpointInspector,
        ReplayCheckpointCriteria $criteria,
        PrunePolicy $policy,
        ?\DateTimeImmutable $now = null,
        bool $allowEmptyCheckpointQuery = false,
    ): RetentionPlan {
        return $this->plan(
            $this->resolveCheckpointsForQuery($checkpointInspector, $criteria, $allowEmptyCheckpointQuery),
            $policy,
            $now,
        );
    }

    public function pruneForCheckpointQuery(
        ReplayCheckpointInspectorInterface $checkpointInspector,
        ReplayCheckpointCriteria $criteria,
        PrunePolicy $policy,
        ?\DateTimeImmutable $now = null,
        bool $allowEmptyCheckpointQuery = false,
    ): RetentionResult {
        return $this->prune(
            $this->resolveCheckpointsForQuery($checkpointInspector, $criteria, $allowEmptyCheckpointQuery),
            $policy,
            $now,
        );
    }

    /**
     * @return list<ReplayCheckpoint>
     *
     * @throws RetentionException
     */
    private function resolveCheckpointsForQuery(
        ReplayCheckpointInspectorInterface $checkpointInspector,
        ReplayCheckpointCriteria $criteria,
        bool $allowEmptyCheckpointQuery,
    ): array {
        $checkpoints = $checkpointInspector->find($criteria);
        if ($checkpoints === [] && !$allowEmptyCheckpointQuery) {
            throw new RetentionException(
                'CheckpointAwarePruner: checkpoint query matched no active checkpoints; '
                . 'pass allowEmptyCheckpointQuery: true only for an intentional uncheckpointed prune.',
            );
        }

        return $checkpoints;
    }

    /**
     * @return list<array{record: StoredReplayRecord, bytes: int}>
     *
     * @throws RetentionException
     */
    private function loadEntries(): array
    {
        if (!file_exists($this->artifactFilePath)) {
            return [];
        }

        $handle = @fopen($this->artifactFilePath, 'r');
        if ($handle === false) {
            throw new RetentionException(
                "CheckpointAwarePruner: failed to open artifact file for planning: {$this->artifactFilePath}",
            );
        }

        $entries = [];

        try {
            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line === false || trim($line) === '') {
                    continue;
                }

                try {
                    $entries[] = [
                        'record' => $this->serializer->deserialize($line),
                        'bytes' => strlen($line),
                    ];
                } catch (SerializationException $e) {
                    throw new RetentionException(
                        "CheckpointAwarePruner: artifact stream contains malformed persisted data and cannot be pruned safely.",
                        previous: $e,
                    );
                }
            }
        } finally {
            fclose($handle);
        }

        return $entries;
    }

    /**
     * @param list<array{record: StoredReplayRecord, bytes: int}> $retainedEntries
     *
     * @throws RetentionException
     */
    private function rewriteRetainedEntries(array $retainedEntries): void
    {
        $tmpPath = $this->artifactFilePath . '.tmp';
        $handle = @fopen($tmpPath, 'wb');
        if ($handle === false) {
            throw new RetentionException(
                "CheckpointAwarePruner: failed to open temp file for rewrite: {$tmpPath}",
            );
        }

        try {
            foreach ($retainedEntries as $entry) {
                $line = $this->serializer->serialize($entry['record']) . "\n";
                if (@fwrite($handle, $line) === false) {
                    throw new RetentionException(
                        "CheckpointAwarePruner: failed to write retained record to temp file: {$tmpPath}",
                    );
                }
            }

            fflush($handle);
        } finally {
            fclose($handle);
        }

        if (!@rename($tmpPath, $this->artifactFilePath)) {
            @unlink($tmpPath);
            throw new RetentionException(
                "CheckpointAwarePruner: failed to atomically replace artifact file: {$this->artifactFilePath}",
            );
        }
    }

    /**
     * @param callable(): RetentionResult $operation
     *
     * @throws RetentionException
     */
    private function withExclusiveRewriteLock(callable $operation): RetentionResult
    {
        $lockHandle = @fopen($this->lockFilePath, 'c');
        if ($lockHandle === false) {
            throw new RetentionException(
                "CheckpointAwarePruner: failed to open artifact coordination lock: {$this->lockFilePath}",
            );
        }

        try {
            if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                throw new RetentionException(
                    "CheckpointAwarePruner: artifact coordination lock is held; pruning cannot safely rewrite now: {$this->lockFilePath}",
                );
            }

            return $operation();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
