<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Fixtures;

use Apntalk\EslReplay\Contracts\ReplayInjectorInterface;
use Apntalk\EslReplay\Execution\InjectionResult;
use Apntalk\EslReplay\Execution\ReplayExecutionCandidate;

final class FakeReplayInjector implements ReplayInjectorInterface
{
    /** @var list<int> */
    public array $injectedSequences = [];

    public function inject(ReplayExecutionCandidate $candidate): InjectionResult
    {
        $this->injectedSequences[] = $candidate->appendSequence;

        return new InjectionResult(
            action: 'injected',
            metadata: [
                'candidate_sequence' => $candidate->appendSequence,
                'artifact_name' => $candidate->artifactName,
            ],
        );
    }
}
