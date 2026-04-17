<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Read;

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
        $fromCorrelation = self::stringValue($correlationIds, 'replay_session_id');
        if ($fromCorrelation !== null) {
            return $fromCorrelation;
        }

        return self::stringValue($runtimeFlags, 'replay_session_id');
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
        return self::stringValue($runtimeFlags, 'pbx_node_slug');
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
        return self::stringValue($runtimeFlags, 'worker_session_id');
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
