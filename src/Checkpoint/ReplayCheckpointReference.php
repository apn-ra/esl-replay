<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Checkpoint;

use Apntalk\EslReplay\Artifact\OperatorIdentityKeys;

/**
 * First-class operational checkpoint write reference.
 *
 * The reference keeps the durable checkpoint key explicit while also attaching
 * a small set of stable operational identity anchors as metadata.
 */
final readonly class ReplayCheckpointReference
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $replaySessionId = null,
        public readonly ?string $jobUuid = null,
        public readonly ?string $pbxNodeSlug = null,
        public readonly ?string $workerSessionId = null,
        public readonly ?string $recoveryGenerationId = null,
        public readonly array $metadata = [],
    ) {
        if (trim($this->key) === '') {
            throw new \InvalidArgumentException('ReplayCheckpointReference: key must not be empty.');
        }

        foreach ([
            'replaySessionId' => $this->replaySessionId,
            'jobUuid' => $this->jobUuid,
            'pbxNodeSlug' => $this->pbxNodeSlug,
            'workerSessionId' => $this->workerSessionId,
            'recoveryGenerationId' => $this->recoveryGenerationId,
        ] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw new \InvalidArgumentException("ReplayCheckpointReference: {$field} must not be empty when provided.");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataWithIdentityAnchors(): array
    {
        return array_merge($this->metadata, array_filter([
            OperatorIdentityKeys::REPLAY_SESSION_ID => $this->replaySessionId,
            'job_uuid' => $this->jobUuid,
            OperatorIdentityKeys::PBX_NODE_SLUG => $this->pbxNodeSlug,
            OperatorIdentityKeys::WORKER_SESSION_ID => $this->workerSessionId,
            \Apntalk\EslReplay\Recovery\RecoveryMetadataKeys::RECOVERY_GENERATION_ID => $this->recoveryGenerationId,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
