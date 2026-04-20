<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

use Apntalk\EslReplay\Artifact\OperatorIdentityKeys;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Contracts\ReplayArtifactReaderInterface;
use Apntalk\EslReplay\Exceptions\CheckpointException;
use Apntalk\EslReplay\Read\ReplayInspectionFields;
use Apntalk\EslReplay\Read\ReplayReadCriteria;

/**
 * Resolves bounded reconstruction windows from persisted checkpoints.
 */
final readonly class CheckpointReconstructionWindowResolver
{
    public function __construct(
        private ReplayArtifactReaderInterface $reader,
    ) {}

    public function resolve(
        ReplayCheckpoint $checkpoint,
        ?ReplayReadCriteria $criteria = null,
        ?int $untilAppendSequence = null,
        int $batchLimit = 100,
    ): ReconstructionWindow {
        $window = new ReconstructionWindow(
            from: $checkpoint->cursor,
            untilAppendSequence: $untilAppendSequence,
            criteria: $criteria,
            batchLimit: $batchLimit,
            checkpointKey: $checkpoint->key,
            checkpointMetadata: $checkpoint->metadata,
        );

        $this->assertWindowCompatibleWithCheckpoint($window, $checkpoint);

        return $window;
    }

    private function assertWindowCompatibleWithCheckpoint(ReconstructionWindow $window, ReplayCheckpoint $checkpoint): void
    {
        $records = $this->reader->readFromCursor($window->from, 1, $window->criteria);
        if ($records === []) {
            return;
        }

        $nextRecord = $records[0];
        $expectedReplaySessionId = $checkpoint->metadata[OperatorIdentityKeys::REPLAY_SESSION_ID] ?? null;
        if (
            is_string($expectedReplaySessionId)
            && ReplayInspectionFields::replaySessionId($nextRecord) !== null
            && ReplayInspectionFields::replaySessionId($nextRecord) !== $expectedReplaySessionId
        ) {
            throw new CheckpointException(
                sprintf(
                    'Checkpoint reconstruction window is incompatible: expected replay_session_id %s, observed %s at append_sequence %d.',
                    $expectedReplaySessionId,
                    ReplayInspectionFields::replaySessionId($nextRecord),
                    $nextRecord->appendSequence,
                ),
            );
        }

        $expectedGenerationId = $checkpoint->metadata[RecoveryMetadataKeys::RECOVERY_GENERATION_ID] ?? null;
        $observedGenerationId = RuntimeTruthExtractor::recoveryGenerationId($nextRecord);
        if (
            is_string($expectedGenerationId)
            && $observedGenerationId !== null
            && $observedGenerationId !== $expectedGenerationId
        ) {
            throw new CheckpointException(
                sprintf(
                    'Checkpoint reconstruction window is incompatible: expected recovery_generation_id %s, observed %s at append_sequence %d.',
                    $expectedGenerationId,
                    $observedGenerationId,
                    $nextRecord->appendSequence,
                ),
            );
        }
    }
}
