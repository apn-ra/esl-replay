<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class ReconstructionVerdict
{
    /**
     * @param list<ReconstructionIssue> $issues
     */
    public function __construct(
        public string $posture,
        public array $issues,
    ) {
        if (trim($this->posture) === '') {
            throw new \InvalidArgumentException('ReconstructionVerdict: posture must not be empty.');
        }
    }
}
