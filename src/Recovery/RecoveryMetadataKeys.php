<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

/**
 * Stable cross-package metadata keys for bounded recovery/evidence projection.
 *
 * Upstream artifact producers may emit these inside payload snapshots,
 * replay_metadata, runtime_flags, or correlation_ids. apntalk/esl-replay
 * consumes them deterministically from stored artifacts only.
 */
final class RecoveryMetadataKeys
{
    public const RECOVERY_GENERATION_ID = 'recovery_generation_id';
    public const RETRY_POSTURE = 'retry_posture';
    public const DRAIN_POSTURE = 'drain_posture';
    public const RECONSTRUCTION_POSTURE = 'reconstruction_posture';
    public const REPLAY_CONTINUITY_POSTURE = 'replay_continuity_posture';
    public const OPERATION_ID = 'operation_id';
    public const OPERATION_KIND = 'operation_kind';
    public const OPERATION_STATE = 'operation_state';
    public const BGAPI_JOB_UUID = 'bgapi_job_uuid';
    public const TERMINAL_PUBLICATION_ID = 'terminal_publication_id';
    public const TERMINAL_PUBLICATION_STATUS = 'terminal_publication_status';
    public const LIFECYCLE_SEMANTIC = 'lifecycle_semantic';

    private function __construct() {}
}
