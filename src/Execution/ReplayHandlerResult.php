<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Execution;

/**
 * Structured result returned by a replay handler.
 */
final readonly class ReplayHandlerResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $action,
        public readonly array $metadata = [],
    ) {
        if (trim($action) === '') {
            throw new \InvalidArgumentException('ReplayHandlerResult: action must not be empty.');
        }
    }
}
