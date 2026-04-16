<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Fixtures;

use Apntalk\EslReplay\Contracts\ReplayRecordHandlerInterface;
use Apntalk\EslReplay\Execution\ReplayHandlerResult;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

final class FakeReplayRecordHandler implements ReplayRecordHandlerInterface
{
    /** @var list<int> */
    public array $handledSequences = [];

    public function __construct(
        private readonly string $action = 'handled',
        private readonly bool $shouldThrow = false,
    ) {}

    public function handle(StoredReplayRecord $record): ReplayHandlerResult
    {
        if ($this->shouldThrow) {
            throw new \RuntimeException("handler failure for sequence {$record->appendSequence}");
        }

        $this->handledSequences[] = $record->appendSequence;

        return new ReplayHandlerResult(
            action: $this->action,
            metadata: [
                'handled_sequence' => $record->appendSequence,
                'artifact_name' => $record->artifactName,
            ],
        );
    }
}
