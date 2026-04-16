<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Exceptions;

/**
 * Raised when a checkpoint cannot be saved, loaded, or validated.
 *
 * Note: checkpoints restore artifact-processing progress only.
 * They do not restore live FreeSWITCH socket sessions.
 */
class CheckpointException extends ReplayException
{
}
