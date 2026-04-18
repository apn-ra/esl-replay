<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Execution;

use Apntalk\EslReplay\Config\ExecutionConfig;
use Apntalk\EslReplay\Contracts\OfflineReplayExecutorInterface;
use Apntalk\EslReplay\Contracts\ReplayArtifactReaderInterface;
use Apntalk\EslReplay\Contracts\ReplayInjectorInterface;
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
 * records outcomes. If a handler registry is supplied, matching records are
 * dispatched through exact artifact-name handler lookup.
 *
 * Guarded re-injection is available only when explicitly enabled with an
 * allowlist and a caller-supplied injector. It remains separate from ordinary
 * offline replay and is disabled by default.
 */
final class OfflineReplayExecutor implements OfflineReplayExecutorInterface
{
    private function __construct(
        private readonly ExecutionConfig $config,
        private readonly ReplayArtifactReaderInterface $reader,
        private readonly ?ReplayHandlerRegistry $handlers = null,
        private readonly ?ReplayInjectorInterface $injector = null,
        private readonly ?ArtifactExecutabilityClassifier $classifier = null,
    ) {}

    /**
     * Primary stable entry point.
     *
     * @throws \InvalidArgumentException if ExecutionConfig contains invalid settings
     */
    public static function make(
        ExecutionConfig $config,
        ReplayArtifactReaderInterface $reader,
        ?ReplayHandlerRegistry $handlers = null,
        ?ReplayInjectorInterface $injector = null,
        ?ArtifactExecutabilityClassifier $classifier = null,
    ): OfflineReplayExecutorInterface {
        if ($config->reinjectionEnabled && $injector === null) {
            throw new \InvalidArgumentException(
                'OfflineReplayExecutor: a ReplayInjectorInterface implementation is required when reinjectionEnabled is true.',
            );
        }

        return new self($config, $reader, $handlers, $injector, $classifier ?? new ArtifactExecutabilityClassifier());
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
     * When plan->isDryRun is false, records are processed observationally,
     * dispatched to exact-match handlers, or passed through guarded re-injection
     * when that higher-risk mode is explicitly enabled.
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
            $handler = $this->handlers?->forArtifact($record->artifactName);
            $candidate = $this->reinjectionGuard() !== null
                ? $this->classifier?->classify($record, $this->reinjectionGuard())
                : null;
            $reinjectionReason = $this->reinjectionGuard() !== null
                ? $this->classifier?->rejectionReason($record, $this->reinjectionGuard())
                : null;

            $outcomes[] = [
                'record_id'       => $record->id->value,
                'artifact_name'   => $record->artifactName,
                'append_sequence' => $record->appendSequence,
                'action'          => 'dry_run_skip',
                'would_dispatch_handler' => $handler !== null,
                'handler'         => $handler !== null ? $handler::class : null,
                'would_reinject'  => $candidate !== null,
                'reinjection_reason' => $reinjectionReason,
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
     * Live/observational mode: record outcomes, dispatching exact-match handlers
     * when a registry is configured. Unhandled records remain observational.
     */
    private function executeObservational(OfflineReplayPlan $plan): OfflineReplayResult
    {
        if ($this->config->reinjectionEnabled) {
            return $this->executeReinjection($plan);
        }

        $outcomes = [];
        $processedCount = 0;

        try {
            foreach ($plan->records as $record) {
                $handler = $this->handlers?->forArtifact($record->artifactName);

                if ($handler === null) {
                    $outcomes[] = [
                        'record_id'        => $record->id->value,
                        'artifact_name'    => $record->artifactName,
                        'artifact_version' => $record->artifactVersion,
                        'append_sequence'  => $record->appendSequence,
                        'action'           => 'observed',
                        'handler'          => null,
                    ];
                    $processedCount++;
                    continue;
                }

                $result = $handler->handle($record);

                $outcomes[] = [
                    'record_id'        => $record->id->value,
                    'artifact_name'    => $record->artifactName,
                    'artifact_version' => $record->artifactVersion,
                    'append_sequence'  => $record->appendSequence,
                    'action'           => $result->action,
                    'handler'          => $handler::class,
                    'metadata'         => $result->metadata,
                ];
                $processedCount++;
            }
        } catch (\Throwable $e) {
            return new OfflineReplayResult(
                plan: $plan,
                success: false,
                processedCount: $processedCount,
                skippedCount: 0,
                outcomes: $outcomes,
                executedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                error: $e->getMessage(),
            );
        }

        return new OfflineReplayResult(
            plan: $plan,
            success: true,
            processedCount: $processedCount,
            skippedCount: 0,
            outcomes: $outcomes,
            executedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    private function executeReinjection(OfflineReplayPlan $plan): OfflineReplayResult
    {
        $guard = $this->reinjectionGuard();
        $outcomes = [];
        $processedCount = 0;
        $skippedCount = 0;

        if ($guard === null || $this->injector === null || $this->classifier === null) {
            throw new \LogicException('OfflineReplayExecutor: reinjection mode requires guard, injector, and classifier.');
        }

        try {
            foreach ($plan->records as $record) {
                $candidate = $this->classifier->classify($record, $guard);
                if ($candidate === null) {
                    $skippedCount++;
                    $outcomes[] = [
                        'record_id'        => $record->id->value,
                        'artifact_name'    => $record->artifactName,
                        'artifact_version' => $record->artifactVersion,
                        'append_sequence'  => $record->appendSequence,
                        'action'           => 'reinjection_rejected',
                        'reason'           => $this->classifier->rejectionReason($record, $guard),
                    ];
                    continue;
                }

                $result = $this->injector->inject($candidate);
                $processedCount++;
                $outcomes[] = [
                    'record_id'        => $record->id->value,
                    'artifact_name'    => $record->artifactName,
                    'artifact_version' => $record->artifactVersion,
                    'append_sequence'  => $record->appendSequence,
                    'action'           => $result->action,
                    'metadata'         => $result->metadata,
                ];
            }
        } catch (\Throwable $e) {
            return new OfflineReplayResult(
                plan: $plan,
                success: false,
                processedCount: $processedCount,
                skippedCount: $skippedCount,
                outcomes: $outcomes,
                executedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                error: $e->getMessage(),
            );
        }

        return new OfflineReplayResult(
            plan: $plan,
            success: true,
            processedCount: $processedCount,
            skippedCount: $skippedCount,
            outcomes: $outcomes,
            executedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    private function reinjectionGuard(): ?InjectionGuard
    {
        if (!$this->config->reinjectionEnabled) {
            return null;
        }

        return new InjectionGuard($this->config->reinjectionArtifactAllowlist);
    }
}
