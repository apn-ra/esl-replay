<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Execution;

use Apntalk\EslReplay\Storage\StoredReplayRecord;

final class ArtifactExecutabilityClassifier
{
    /**
     * Artifact types that can become execution candidates under strict policy.
     *
     * @var list<string>
     */
    private const EXECUTABLE_ARTIFACTS = [
        'api.dispatch',
        'bgapi.dispatch',
    ];

    public function classify(
        StoredReplayRecord $record,
        InjectionGuard $guard,
    ): ?ReplayExecutionCandidate {
        if (!$this->isIntrinsicExecutable($record->artifactName)) {
            return null;
        }

        if (!$guard->allows($record->artifactName)) {
            return null;
        }

        return new ReplayExecutionCandidate(
            sourceRecord: $record,
            artifactName: $record->artifactName,
            appendSequence: $record->appendSequence,
            capturedAt: $record->captureTimestamp,
            payload: $record->payload,
        );
    }

    public function rejectionReason(StoredReplayRecord $record, InjectionGuard $guard): string
    {
        if (!$this->isIntrinsicExecutable($record->artifactName)) {
            return 'artifact type is observational and not injectable';
        }

        if (!$guard->allows($record->artifactName)) {
            return 'artifact type is not allowlisted for reinjection';
        }

        return 'eligible';
    }

    private function isIntrinsicExecutable(string $artifactName): bool
    {
        return in_array($artifactName, self::EXECUTABLE_ARTIFACTS, true);
    }
}
