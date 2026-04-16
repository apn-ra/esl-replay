<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Execution;

use Apntalk\EslReplay\Config\ExecutionConfig;
use Apntalk\EslReplay\Contracts\OfflineReplayExecutorInterface;
use Apntalk\EslReplay\Contracts\ReplayArtifactReaderInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;

/**
 * Primary stable entry point for offline replay execution.
 *
 * Usage:
 *   $executor = OfflineReplayExecutor::make($config, $reader);
 *   $plan     = $executor->plan($cursor);
 *   $result   = $executor->execute($plan);
 *
 * Offline replay operates entirely on stored artifacts.
 * It does NOT require a live FreeSWITCH socket.
 *
 * Primary use cases: diagnostics, test reconstruction, timeline analysis,
 * audit reconstruction, report generation.
 *
 * Dry-run mode (the default): plan() and execute() both work, but execute()
 * marks every record as 'dry_run_skip' and dispatches no handlers. Use dry-run
 * to inspect what would be replayed before committing.
 *
 * Live mode (dryRun=false): execute() processes records observationally and
 * records outcomes. Handler dispatch is reserved for a future implementation
 * phase; in this release live mode records outcomes without side-effects.
 *
 * Re-injection is NOT available in this release. ExecutionConfig::reinjectionEnabled
 * must remain false and is enforced at construction time.
 */
final class OfflineReplayExecutor implements OfflineReplayExecutorInterface
{
    private function __construct(
        private readonly ExecutionConfig $config,
        private readonly ReplayArtifactReaderInterface $reader,
    ) {}

    /**
     * Primary stable entry point.
     *
     * @throws \InvalidArgumentException if ExecutionConfig contains invalid settings
     */
    public static function make(
        ExecutionConfig $config,
        ReplayArtifactReaderInterface $reader,
    ): OfflineReplayExecutorInterface {
        return new self($config, $reader);
    }

    /**
     * Build an offline replay plan from stored artifacts starting at $from.
     *
     * Reads up to ExecutionConfig::batchLimit records whose appendSequence is
     * strictly greater than $from->lastConsumedSequence. Returns a plan that
     * can be inspected before execution.
     *
     * An empty plan (recordCount === 0) means no new records are available.
     */
    public function plan(ReplayReadCursor $from): OfflineReplayPlan
    {
        $records = $this->reader->readFromCursor($from, $this->config->batchLimit);

        return new OfflineReplayPlan(
            from: $from,
            recordCount: count($records),
            records: $records,
            isDryRun: $this->config->dryRun,
            plannedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /**
     * Execute a previously built plan.
     *
     * When plan->isDryRun is true, all records are skipped with action 'dry_run_skip'.
     * When plan->isDryRun is false, records are processed observationally.
     *
     * Handler dispatch is reserved for a future implementation phase.
     */
    public function execute(OfflineReplayPlan $plan): OfflineReplayResult
    {
        if ($plan->isDryRun) {
            return $this->executeDryRun($plan);
        }

        return $this->executeObservational($plan);
    }

    /**
     * Dry-run: describe what would execute without any side effects.
     */
    private function executeDryRun(OfflineReplayPlan $plan): OfflineReplayResult
    {
        $outcomes = [];
        foreach ($plan->records as $record) {
            $outcomes[] = [
                'record_id'       => $record->id->value,
                'artifact_name'   => $record->artifactName,
                'append_sequence' => $record->appendSequence,
                'action'          => 'dry_run_skip',
            ];
        }

        return new OfflineReplayResult(
            plan: $plan,
            success: true,
            processedCount: 0,
            skippedCount: $plan->recordCount,
            outcomes: $outcomes,
            executedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /**
     * Live/observational mode: record outcomes without dispatching handlers.
     *
     * Handler dispatch is a future implementation concern (Phase 7 of the plan).
     * In this release, all records are marked as 'observed'.
     */
    private function executeObservational(OfflineReplayPlan $plan): OfflineReplayResult
    {
        $outcomes = [];
        foreach ($plan->records as $record) {
            $outcomes[] = [
                'record_id'       => $record->id->value,
                'artifact_name'   => $record->artifactName,
                'artifact_version' => $record->artifactVersion,
                'append_sequence' => $record->appendSequence,
                'action'          => 'observed',
            ];
        }

        return new OfflineReplayResult(
            plan: $plan,
            success: true,
            processedCount: $plan->recordCount,
            skippedCount: 0,
            outcomes: $outcomes,
            executedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
