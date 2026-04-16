<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Contracts;

use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Execution\OfflineReplayPlan;
use Apntalk\EslReplay\Execution\OfflineReplayResult;

/**
 * Plans and executes offline replay from stored artifacts.
 *
 * Offline replay operates entirely on stored artifacts.
 * It does NOT require a live FreeSWITCH socket.
 *
 * Primary use cases: diagnostics, test reconstruction, timeline analysis,
 * audit reconstruction, report generation.
 *
 * The primary stable entry point is:
 *   OfflineReplayExecutor::make(ExecutionConfig $config, ReplayArtifactReaderInterface $reader)
 */
interface OfflineReplayExecutorInterface
{
    /**
     * Build a replay plan from stored artifacts starting at $from.
     *
     * The plan describes what would be executed without executing it.
     * Inspect the plan before calling execute().
     */
    public function plan(ReplayReadCursor $from): OfflineReplayPlan;

    /**
     * Execute a previously built plan.
     *
     * When the plan's isDryRun is true, execution produces outcomes without
     * dispatching any side effects.
     */
    public function execute(OfflineReplayPlan $plan): OfflineReplayResult;
}
