<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Checkpoint;

use Apntalk\EslReplay\Contracts\ReplayArtifactReaderInterface;
use Apntalk\EslReplay\Exceptions\RetentionException;

/**
 * Validates whether saved replay progress remains compatible with the current
 * retained artifact stream.
 */
final class CheckpointCompatibilityValidator
{
    /**
     * @param list<ReplayCheckpoint> $checkpoints
     *
     * @throws RetentionException when a visible retained-data gap exists after a checkpoint cursor
     */
    public function assertCompatible(
        ReplayArtifactReaderInterface $reader,
        array $checkpoints,
    ): void {
        $firstRecord = $reader->readFromCursor($reader->openCursor(), 1)[0] ?? null;
        if ($firstRecord === null) {
            return;
        }

        foreach ($checkpoints as $checkpoint) {
            $requiredNextSequence = $checkpoint->cursor->lastConsumedSequence + 1;
            if ($firstRecord->appendSequence > $requiredNextSequence) {
                throw new RetentionException(
                    "Checkpoint '{$checkpoint->key}' is incompatible with the retained stream. "
                    . "The stream now starts at appendSequence {$firstRecord->appendSequence}, "
                    . "but replay would need sequence {$requiredNextSequence} next.",
                );
            }
        }
    }
}
