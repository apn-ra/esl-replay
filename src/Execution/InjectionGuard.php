<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Execution;

final readonly class InjectionGuard
{
    /**
     * @param list<string> $allowlistedArtifactNames
     */
    public function __construct(public readonly array $allowlistedArtifactNames)
    {
        if ($allowlistedArtifactNames === []) {
            throw new \InvalidArgumentException(
                'InjectionGuard: allowlistedArtifactNames must not be empty.',
            );
        }

        foreach ($allowlistedArtifactNames as $artifactName) {
            if (trim($artifactName) === '') {
                throw new \InvalidArgumentException(
                    'InjectionGuard: allowlisted artifact names must not be empty.',
                );
            }
        }
    }

    public function allows(string $artifactName): bool
    {
        return in_array($artifactName, $this->allowlistedArtifactNames, true);
    }
}
