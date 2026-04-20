<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Adapter\Filesystem;

use Apntalk\EslReplay\Exceptions\ArtifactPersistenceException;

/**
 * Long-lived package writer ownership lock for one filesystem artifact stream.
 *
 * This is distinct from the short-lived append/prune coordination lock.
 */
final class FilesystemWriterOwnershipLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $lockFilePath)
    {
        $handle = @fopen($this->lockFilePath, 'c');
        if ($handle === false) {
            throw new ArtifactPersistenceException(
                "Filesystem writer ownership: failed to open ownership lock: {$this->lockFilePath}",
            );
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new ArtifactPersistenceException(
                "Filesystem writer ownership: another package writer already owns this artifact stream: {$this->lockFilePath}",
            );
        }

        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
