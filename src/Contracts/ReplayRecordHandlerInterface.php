<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Contracts;

use Apntalk\EslReplay\Execution\ReplayHandlerResult;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Handles a stored replay record during offline replay execution.
 *
 * Handlers are optional and explicitly bound by artifact name through a
 * ReplayHandlerRegistry. They do not change storage semantics or cursor
 * semantics; they only influence offline replay execution outcomes.
 */
interface ReplayRecordHandlerInterface
{
    public function handle(StoredReplayRecord $record): ReplayHandlerResult;
}
