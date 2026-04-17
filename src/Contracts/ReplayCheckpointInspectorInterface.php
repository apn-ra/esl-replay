<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Contracts;

use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria;

/**
 * Bounded checkpoint lookup surface for operational recovery flows.
 *
 * This is intentionally not a general checkpoint search API. Implementations
 * must preserve conservative exact-match semantics over a small set of stable
 * operational identity fields.
 */
interface ReplayCheckpointInspectorInterface
{
    /**
     * @return list<ReplayCheckpoint>
     */
    public function find(ReplayCheckpointCriteria $criteria): array;
}
