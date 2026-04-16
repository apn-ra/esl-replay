<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Execution;

use Apntalk\EslReplay\Contracts\ReplayRecordHandlerInterface;

/**
 * Bounded artifact-name-to-handler mapping for offline replay.
 *
 * This is intentionally not a dynamic dispatch DSL. Resolution is an exact
 * artifact-name match only.
 */
final readonly class ReplayHandlerRegistry
{
    /**
     * @param array<string, ReplayRecordHandlerInterface> $handlersByArtifactName
     */
    public function __construct(private readonly array $handlersByArtifactName = [])
    {
        foreach ($this->handlersByArtifactName as $artifactName => $handler) {
            if (trim((string) $artifactName) === '') {
                throw new \InvalidArgumentException(
                    'ReplayHandlerRegistry: artifact name keys must not be empty.',
                );
            }

            if (!$handler instanceof ReplayRecordHandlerInterface) {
                throw new \InvalidArgumentException(
                    'ReplayHandlerRegistry: every handler must implement ReplayRecordHandlerInterface.',
                );
            }
        }
    }

    public function forArtifact(string $artifactName): ?ReplayRecordHandlerInterface
    {
        return $this->handlersByArtifactName[$artifactName] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->handlersByArtifactName === [];
    }
}
