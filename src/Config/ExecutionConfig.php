<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Config;

/**
 * Immutable configuration for the offline replay execution layer.
 *
 * Re-injection is disabled by default and requires an explicit future release
 * to enable. Do not set reinjectionEnabled=true in this version.
 */
final readonly class ExecutionConfig
{
    /**
     * @param bool $dryRun             When true, replay execution plans but does not execute handlers.
     *                                 Defaults to true. Offline replay is the primary safe mode.
     * @param bool $reinjectionEnabled Reserved for a future controlled re-injection release.
     *                                 Must remain false. Throws if set to true in this version.
     * @param int  $batchLimit         Maximum number of records to include in a single replay plan.
     *                                 Must be >= 1. Defaults to 500.
     */
    public function __construct(
        public readonly bool $dryRun = true,
        public readonly bool $reinjectionEnabled = false,
        public readonly int $batchLimit = 500,
    ) {
        if ($reinjectionEnabled) {
            throw new \InvalidArgumentException(
                'ExecutionConfig: reinjectionEnabled must remain false. '
                . 'Controlled re-injection is not implemented in this release. '
                . 'It is a guarded, optional feature reserved for a later phase.'
            );
        }

        if ($batchLimit < 1) {
            throw new \InvalidArgumentException(
                'ExecutionConfig: batchLimit must be >= 1.'
            );
        }
    }
}
