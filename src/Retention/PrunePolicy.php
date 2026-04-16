<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Retention;

/**
 * Conservative retention policy for explicit pruning.
 */
final readonly class PrunePolicy
{
    public function __construct(
        public readonly ?\DateInterval $maxRecordAge = null,
        public readonly ?int $maxStreamBytes = null,
        public readonly int $protectedRecordCount = 0,
    ) {
        if ($this->maxStreamBytes !== null && $this->maxStreamBytes < 0) {
            throw new \InvalidArgumentException('PrunePolicy: maxStreamBytes must be >= 0 when provided.');
        }

        if ($this->protectedRecordCount < 0) {
            throw new \InvalidArgumentException('PrunePolicy: protectedRecordCount must be >= 0.');
        }
    }
}
