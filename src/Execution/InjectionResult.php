<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Execution;

final readonly class InjectionResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $action,
        public readonly array $metadata = [],
    ) {
        if (trim($action) === '') {
            throw new \InvalidArgumentException('InjectionResult: action must not be empty.');
        }
    }
}
