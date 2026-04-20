<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Artifact;

/**
 * Stable cross-package keys for operator-facing replay identity.
 *
 * Upstream artifact producers such as apntalk/esl-react should use these exact
 * keys when emitting replay identity in correlation_ids or runtime_flags.
 */
final class OperatorIdentityKeys
{
    public const REPLAY_SESSION_ID = 'replay_session_id';
    public const PBX_NODE_SLUG = 'pbx_node_slug';
    public const WORKER_SESSION_ID = 'worker_session_id';

    private function __construct() {}
}
