<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Config;

/**
 * Immutable configuration for the offline replay execution layer.
 *
 * Re-injection is disabled by default. When enabled, it remains a separately
 * guarded, higher-risk mode that requires an explicit allowlist.
 */
final readonly class ExecutionConfig
{
    /**
     * @param bool $dryRun             When true, replay execution plans but does not execute handlers.
     *                                 Defaults to true. Offline replay is the primary safe mode.
     * @param bool $reinjectionEnabled Enables the guarded re-injection execution path.
     *                                 Disabled by default.
     * @param list<string> $reinjectionArtifactAllowlist Explicit allowlist for re-injection.
     *                                 Required when reinjectionEnabled is true.
     * @param int  $batchLimit         Maximum number of records to include in a single replay plan.
     *                                 Must be >= 1. Defaults to 500.
     */
    public function __construct(
        public readonly bool $dryRun = true,
        public readonly bool $reinjectionEnabled = false,
        public readonly array $reinjectionArtifactAllowlist = [],
        public readonly int $batchLimit = 500,
    ) {
        if ($reinjectionEnabled && $reinjectionArtifactAllowlist === []) {
            throw new \InvalidArgumentException(
                'ExecutionConfig: reinjectionArtifactAllowlist must be provided when reinjectionEnabled is true.'
            );
        }

        if ($batchLimit < 1) {
            throw new \InvalidArgumentException(
                'ExecutionConfig: batchLimit must be >= 1.'
            );
        }

        foreach ($reinjectionArtifactAllowlist as $artifactName) {
            if (!is_string($artifactName) || trim($artifactName) === '') {
                throw new \InvalidArgumentException(
                    'ExecutionConfig: reinjectionArtifactAllowlist entries must be non-empty strings.',
                );
            }
        }
    }
}
