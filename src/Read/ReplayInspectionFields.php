<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Read;

use Apntalk\EslReplay\Artifact\OperatorIdentityKeys;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Internal helper for extracting bounded operator-inspection identity fields.
 */
final class ReplayInspectionFields
{
    private function __construct() {}

    public static function replaySessionId(StoredReplayRecord $record): ?string
    {
        $fromCorrelation = self::replaySessionIdFromArrays($record->correlationIds, $record->runtimeFlags);
        if ($fromCorrelation !== null) {
            return $fromCorrelation;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $correlationIds
     * @param array<string, mixed> $runtimeFlags
     */
    public static function replaySessionIdFromArrays(array $correlationIds, array $runtimeFlags): ?string
    {
        $fromCorrelation = self::stringValue($correlationIds, OperatorIdentityKeys::REPLAY_SESSION_ID);
        if ($fromCorrelation !== null) {
            return $fromCorrelation;
        }

        return self::stringValue($runtimeFlags, OperatorIdentityKeys::REPLAY_SESSION_ID);
    }

    public static function pbxNodeSlug(StoredReplayRecord $record): ?string
    {
        return self::pbxNodeSlugFromRuntimeFlags($record->runtimeFlags);
    }

    /**
     * @param array<string, mixed> $runtimeFlags
     */
    public static function pbxNodeSlugFromRuntimeFlags(array $runtimeFlags): ?string
    {
        return self::stringValue($runtimeFlags, OperatorIdentityKeys::PBX_NODE_SLUG);
    }

    public static function workerSessionId(StoredReplayRecord $record): ?string
    {
        return self::workerSessionIdFromRuntimeFlags($record->runtimeFlags);
    }

    /**
     * @param array<string, mixed> $runtimeFlags
     */
    public static function workerSessionIdFromRuntimeFlags(array $runtimeFlags): ?string
    {
        return self::stringValue($runtimeFlags, OperatorIdentityKeys::WORKER_SESSION_ID);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function stringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
