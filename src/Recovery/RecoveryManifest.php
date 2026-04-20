<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class RecoveryManifest
{
    public function __construct(
        public int $bundleVersion,
        public string $bundleId,
        public ReconstructionWindow $window,
        public int $recordCount,
        public ?int $firstAppendSequence,
        public ?int $lastAppendSequence,
        public ReconstructionVerdict $verdict,
    ) {
        if ($this->bundleVersion < 1) {
            throw new \InvalidArgumentException('RecoveryManifest: bundleVersion must be >= 1.');
        }

        if (trim($this->bundleId) === '') {
            throw new \InvalidArgumentException('RecoveryManifest: bundleId must not be empty.');
        }

        if ($this->recordCount < 0) {
            throw new \InvalidArgumentException('RecoveryManifest: recordCount must be >= 0.');
        }
    }
}
