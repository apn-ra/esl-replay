<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class ExpectedOperationLifecycle
{
    /**
     * @param list<string> $states
     */
    public function __construct(
        public string $operationId,
        public array $states,
        public ?string $finalState = null,
        public ?string $retryPosture = null,
        public ?string $drainPosture = null,
    ) {
        if (trim($this->operationId) === '') {
            throw new \InvalidArgumentException('ExpectedOperationLifecycle: operationId must not be empty.');
        }

        if ($this->states === []) {
            throw new \InvalidArgumentException('ExpectedOperationLifecycle: states must not be empty.');
        }
    }
}
