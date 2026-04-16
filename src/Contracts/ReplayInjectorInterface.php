<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Contracts;

use Apntalk\EslReplay\Execution\InjectionResult;
use Apntalk\EslReplay\Execution\ReplayExecutionCandidate;

/**
 * Executes a guarded replay execution candidate through a caller-supplied
 * injection mechanism.
 *
 * This package does not own live FreeSWITCH socket lifecycle or transport.
 * A concrete injector must be supplied explicitly by the caller.
 */
interface ReplayInjectorInterface
{
    public function inject(ReplayExecutionCandidate $candidate): InjectionResult;
}
