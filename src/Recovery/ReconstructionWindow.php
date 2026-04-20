<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Read\ReplayReadCriteria;

/**
 * Bounded append-ordered reconstruction window over stored artifacts.
 */
final readonly class ReconstructionWindow
{
    /**
     * @param array<string, mixed> $checkpointMetadata
     */
    public function __construct(
        public ReplayReadCursor $from,
        public ?int $untilAppendSequence = null,
        public ?ReplayReadCriteria $criteria = null,
        public int $batchLimit = 100,
        public ?string $checkpointKey = null,
        public array $checkpointMetadata = [],
    ) {
        if ($this->untilAppendSequence !== null && $this->untilAppendSequence < 1) {
            throw new \InvalidArgumentException('ReconstructionWindow: untilAppendSequence must be >= 1 when provided.');
        }

        if ($this->untilAppendSequence !== null && $this->untilAppendSequence <= $this->from->lastConsumedSequence) {
            throw new \InvalidArgumentException(
                'ReconstructionWindow: untilAppendSequence must be greater than from.lastConsumedSequence.',
            );
        }

        if ($this->batchLimit < 1) {
            throw new \InvalidArgumentException('ReconstructionWindow: batchLimit must be >= 1.');
        }

        if ($this->checkpointKey !== null && trim($this->checkpointKey) === '') {
            throw new \InvalidArgumentException('ReconstructionWindow: checkpointKey must not be empty when provided.');
        }
    }
}
