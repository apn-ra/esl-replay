<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Fixtures;

use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;

/**
 * Test-only implementation of CapturedArtifactEnvelope.
 *
 * Provides deterministic defaults and a named constructor for common scenarios.
 * Not part of the production code surface.
 */
final class FakeCapturedArtifact implements CapturedArtifactEnvelope
{
    /**
     * @param array<string, string> $correlationIds
     * @param array<string, mixed>  $runtimeFlags
     * @param array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $artifactVersion = '1',
        private readonly string $artifactName = 'api.dispatch',
        private readonly \DateTimeImmutable $captureTimestamp = new \DateTimeImmutable(
            '2024-01-15T10:00:00.000000+00:00',
        ),
        private readonly ?string $capturePath = null,
        private readonly ?string $connectionGeneration = null,
        private readonly ?string $sessionId = null,
        private readonly ?string $jobUuid = null,
        private readonly ?string $eventName = null,
        private readonly array $correlationIds = [],
        private readonly array $runtimeFlags = [],
        private readonly array $payload = ['command' => 'originate', 'args' => 'sofia/internal/1000'],
    ) {}

    /**
     * Build a minimal api.dispatch artifact for testing.
     *
     * @param array<string, mixed> $payload
     */
    public static function apiDispatch(
        string $sessionId = 'sess-001',
        array $payload = ['command' => 'originate', 'args' => 'sofia/internal/1000'],
    ): self {
        return new self(
            artifactName: 'api.dispatch',
            sessionId: $sessionId,
            payload: $payload,
        );
    }

    /** Build a minimal event.raw artifact for testing. */
    public static function eventRaw(
        string $eventName = 'CHANNEL_CREATE',
        string $sessionId = 'sess-001',
    ): self {
        return new self(
            artifactName: 'event.raw',
            sessionId: $sessionId,
            eventName: $eventName,
            payload: ['Event-Name' => $eventName, 'Core-UUID' => 'core-uuid-001'],
        );
    }

    /** Build a bgapi.dispatch artifact for testing. */
    public static function bgapiDispatch(string $jobUuid = 'job-uuid-001'): self
    {
        return new self(
            artifactName: 'bgapi.dispatch',
            jobUuid: $jobUuid,
            payload: ['command' => 'show', 'args' => 'channels'],
        );
    }

    /**
     * Build an artifact carrying richer runtime-truth snapshots for recovery/evidence tests.
     *
     * @param array<string, mixed> $preparedRecoveryContext
     * @param array<string, mixed> $runtimeRecoverySnapshot
     * @param array<string, mixed> $runtimeOperationSnapshot
     * @param array<string, mixed> $runtimeTerminalPublicationSnapshot
     * @param array<string, mixed> $runtimeLifecycleSemanticSnapshot
     * @param array<string, mixed> $replayMetadata
     * @param array<string, mixed> $runtimeFlags
     * @param array<string, string> $correlationIds
     * @param array<string, mixed> $payload
     */
    public static function enriched(
        string $artifactName,
        \DateTimeImmutable $captureTimestamp,
        ?string $sessionId = null,
        ?string $jobUuid = null,
        array $preparedRecoveryContext = [],
        array $runtimeRecoverySnapshot = [],
        array $runtimeOperationSnapshot = [],
        array $runtimeTerminalPublicationSnapshot = [],
        array $runtimeLifecycleSemanticSnapshot = [],
        array $replayMetadata = [],
        array $runtimeFlags = [],
        array $correlationIds = [],
        array $payload = [],
    ): self {
        return new self(
            artifactName: $artifactName,
            captureTimestamp: $captureTimestamp,
            sessionId: $sessionId,
            jobUuid: $jobUuid,
            correlationIds: $correlationIds,
            runtimeFlags: $runtimeFlags,
            payload: array_filter([
                'prepared_recovery_context' => $preparedRecoveryContext !== [] ? $preparedRecoveryContext : null,
                'runtime_recovery_snapshot' => $runtimeRecoverySnapshot !== [] ? $runtimeRecoverySnapshot : null,
                'runtime_operation_snapshot' => $runtimeOperationSnapshot !== [] ? $runtimeOperationSnapshot : null,
                'runtime_terminal_publication_snapshot' => $runtimeTerminalPublicationSnapshot !== [] ? $runtimeTerminalPublicationSnapshot : null,
                'runtime_lifecycle_semantic_snapshot' => $runtimeLifecycleSemanticSnapshot !== [] ? $runtimeLifecycleSemanticSnapshot : null,
                'replay_metadata' => $replayMetadata !== [] ? $replayMetadata : null,
            ], static fn (mixed $value): bool => $value !== null) + $payload,
        );
    }

    public function getArtifactVersion(): string
    {
        return $this->artifactVersion;
    }

    public function getArtifactName(): string
    {
        return $this->artifactName;
    }

    public function getCaptureTimestamp(): \DateTimeImmutable
    {
        return $this->captureTimestamp;
    }

    public function getCapturePath(): ?string
    {
        return $this->capturePath;
    }

    public function getConnectionGeneration(): ?string
    {
        return $this->connectionGeneration;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getJobUuid(): ?string
    {
        return $this->jobUuid;
    }

    public function getEventName(): ?string
    {
        return $this->eventName;
    }

    /** @return array<string, string> */
    public function getCorrelationIds(): array
    {
        return $this->correlationIds;
    }

    /** @return array<string, mixed> */
    public function getRuntimeFlags(): array
    {
        return $this->runtimeFlags;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
