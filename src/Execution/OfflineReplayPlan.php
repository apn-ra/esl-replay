<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Execution;

use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * A planned offline replay over stored artifacts.
 *
 * Produced by OfflineReplayExecutor::plan(). Describes which records will be
 * processed and under what conditions. Can be inspected before execution.
 *
 * Offline replay operates only on stored artifacts.
 * It does not require a live FreeSWITCH socket.
 */
final readonly class OfflineReplayPlan
{
    /**
     * @param ReplayReadCursor       $from        The cursor from which this plan was built.
     * @param int                    $recordCount Total number of records in the plan.
     * @param list<StoredReplayRecord> $records   The ordered records to be replayed.
     * @param bool                   $isDryRun    When true, execution will describe but not dispatch.
     * @param \DateTimeImmutable     $plannedAt   UTC timestamp when this plan was produced.
     */
    public function __construct(
        public readonly ReplayReadCursor $from,
        public readonly int $recordCount,
        public readonly array $records,
        public readonly bool $isDryRun,
        public readonly \DateTimeImmutable $plannedAt,
    ) {
        if ($recordCount < 0) {
            throw new \InvalidArgumentException('OfflineReplayPlan: recordCount must be >= 0.');
        }

        if (count($records) !== $recordCount) {
            throw new \InvalidArgumentException(
                'OfflineReplayPlan: records count must match recordCount.'
            );
        }
    }

    public function isEmpty(): bool
    {
        return $this->recordCount === 0;
    }
}
