<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Execution;

/**
 * The result of an offline replay execution.
 *
 * Produced by OfflineReplayExecutor::execute(). Contains a summary of what
 * was processed, what was skipped, and any per-record outcomes.
 */
final readonly class OfflineReplayResult
{
    /**
     * @param OfflineReplayPlan          $plan           The plan that was executed.
     * @param bool                       $success        Whether the execution completed without error.
     * @param int                        $processedCount Number of records actually processed.
     * @param int                        $skippedCount   Number of records skipped (dry-run or filtered).
     * @param list<array<string, mixed>> $outcomes       Per-record outcome entries.
     * @param \DateTimeImmutable         $executedAt     UTC timestamp of execution.
     * @param string|null                $error          Error message if success is false.
     */
    public function __construct(
        public readonly OfflineReplayPlan $plan,
        public readonly bool $success,
        public readonly int $processedCount,
        public readonly int $skippedCount,
        public readonly array $outcomes,
        public readonly \DateTimeImmutable $executedAt,
        public readonly ?string $error = null,
    ) {
        if ($processedCount < 0) {
            throw new \InvalidArgumentException('processedCount must be >= 0.');
        }

        if ($skippedCount < 0) {
            throw new \InvalidArgumentException('skippedCount must be >= 0.');
        }
    }
}
