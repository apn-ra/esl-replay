<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Internal projection helper for richer runtime-truth metadata embedded in stored artifacts.
 */
final class RuntimeTruthExtractor
{
    private function __construct() {}

    public static function recoveryGenerationId(StoredReplayRecord $record): ?string
    {
        return self::firstString(
            self::contextsFor($record),
            [
                RecoveryMetadataKeys::RECOVERY_GENERATION_ID,
                'generation_id',
            ],
        );
    }

    public static function replayContinuityPosture(StoredReplayRecord $record): ?string
    {
        return self::firstString(self::contextsFor($record), [RecoveryMetadataKeys::REPLAY_CONTINUITY_POSTURE]);
    }

    public static function retryPosture(StoredReplayRecord $record): ?string
    {
        return self::firstString(self::contextsFor($record), [RecoveryMetadataKeys::RETRY_POSTURE]);
    }

    public static function drainPosture(StoredReplayRecord $record): ?string
    {
        return self::firstString(self::contextsFor($record), [RecoveryMetadataKeys::DRAIN_POSTURE]);
    }

    public static function reconstructionPosture(StoredReplayRecord $record): ?string
    {
        return self::firstString(self::contextsFor($record), [RecoveryMetadataKeys::RECONSTRUCTION_POSTURE]);
    }

    public static function operationId(StoredReplayRecord $record): ?string
    {
        $operationId = self::firstString(
            self::operationContextsFor($record),
            [RecoveryMetadataKeys::OPERATION_ID],
        );

        if ($operationId !== null) {
            return $operationId;
        }

        return $record->jobUuid;
    }

    public static function operationKind(StoredReplayRecord $record): ?string
    {
        return self::firstString(
            self::operationContextsFor($record),
            [RecoveryMetadataKeys::OPERATION_KIND, 'kind'],
        );
    }

    public static function bgapiJobUuid(StoredReplayRecord $record): ?string
    {
        return self::firstString(
            self::operationContextsFor($record),
            [RecoveryMetadataKeys::BGAPI_JOB_UUID, 'job_uuid'],
        ) ?? $record->jobUuid;
    }

    public static function operationState(StoredReplayRecord $record): ?string
    {
        $explicit = self::firstString(
            self::operationContextsFor($record),
            [RecoveryMetadataKeys::OPERATION_STATE, 'state'],
        );

        if ($explicit !== null) {
            return $explicit;
        }

        return match ($record->artifactName) {
            'api.dispatch', 'bgapi.dispatch' => 'accepted',
            'bgapi.ack' => 'accepted',
            'bgapi.complete' => 'completed',
            'api.reply', 'command.reply' => 'completed',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function terminalPublicationSnapshot(StoredReplayRecord $record): ?array
    {
        return self::firstArray(
            self::contextsFor($record),
            [
                'runtime_terminal_publication_snapshot',
            ],
        );
    }

    public static function terminalPublicationId(StoredReplayRecord $record): ?string
    {
        $snapshot = self::terminalPublicationSnapshot($record);

        if ($snapshot === null) {
            return null;
        }

        return self::stringValue($snapshot, [
            RecoveryMetadataKeys::TERMINAL_PUBLICATION_ID,
            'publication_id',
        ]);
    }

    public static function terminalPublicationStatus(StoredReplayRecord $record): ?string
    {
        $snapshot = self::terminalPublicationSnapshot($record);

        if ($snapshot === null) {
            return null;
        }

        return self::stringValue($snapshot, [
            RecoveryMetadataKeys::TERMINAL_PUBLICATION_STATUS,
            'status',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function lifecycleSemanticSnapshot(StoredReplayRecord $record): ?array
    {
        return self::firstArray(
            self::contextsFor($record),
            [
                'runtime_lifecycle_semantic_snapshot',
            ],
        );
    }

    public static function lifecycleSemantic(StoredReplayRecord $record): ?string
    {
        $snapshot = self::lifecycleSemanticSnapshot($record);

        if ($snapshot === null) {
            return null;
        }

        return self::stringValue($snapshot, [RecoveryMetadataKeys::LIFECYCLE_SEMANTIC, 'semantic']);
    }

    public static function lifecyclePosture(StoredReplayRecord $record): ?string
    {
        $snapshot = self::lifecycleSemanticSnapshot($record);

        if ($snapshot === null) {
            return null;
        }

        return self::stringValue($snapshot, ['posture', 'state']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function contextsFor(StoredReplayRecord $record): array
    {
        $contexts = [];

        foreach ([
            'prepared_recovery_context',
            'runtime_recovery_snapshot',
            'runtime_operation_snapshot',
            'runtime_terminal_publication_snapshot',
            'runtime_lifecycle_semantic_snapshot',
            'replay_metadata',
        ] as $payloadKey) {
            $value = $record->payload[$payloadKey] ?? null;
            if (is_array($value)) {
                $contexts[] = $value;
            }
        }

        $contexts[] = $record->runtimeFlags;
        $contexts[] = $record->correlationIds;
        $contexts[] = $record->payload;

        return $contexts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function operationContextsFor(StoredReplayRecord $record): array
    {
        $contexts = [];
        $operationSnapshot = $record->payload['runtime_operation_snapshot'] ?? null;
        if (is_array($operationSnapshot)) {
            $contexts[] = $operationSnapshot;
        }

        $replayMetadata = $record->payload['replay_metadata'] ?? null;
        if (is_array($replayMetadata)) {
            $contexts[] = $replayMetadata;
        }

        $contexts[] = $record->runtimeFlags;
        $contexts[] = $record->correlationIds;
        $contexts[] = $record->payload;

        return $contexts;
    }

    /**
     * @param list<array<string, mixed>> $contexts
     * @param list<string>               $keys
     */
    private static function firstString(array $contexts, array $keys): ?string
    {
        foreach ($contexts as $context) {
            $value = self::stringValue($context, $keys);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $contexts
     * @param list<string>               $keys
     *
     * @return array<string, mixed>|null
     */
    private static function firstArray(array $contexts, array $keys): ?array
    {
        foreach ($contexts as $context) {
            foreach ($keys as $key) {
                $value = $context[$key] ?? null;
                if (is_array($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $keys
     */
    private static function stringValue(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
