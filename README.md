# apntalk/esl-replay

Durable replay artifact platform for FreeSWITCH ESL runtime output.

## What this package is

`apntalk/esl-replay` provides durable storage, deterministic reads, restart-safe
progress recovery, and offline replay for FreeSWITCH ESL artifacts emitted by
`apntalk/esl-react`.

## What this package is NOT

This package does not:
- manage live FreeSWITCH socket connections
- perform reconnect supervision
- own live ESL session state
- restore live runtime continuity after a process restart
- execute business telephony logic
- embed Laravel-specific persistence abstractions

See `docs/architecture.md` for the full boundary description.

## Package family

| Package | Role |
|---|---|
| `apntalk/esl-core` | Protocol substrate, frame/event primitives, shared vocabulary |
| `apntalk/esl-react` | Live async runtime, session supervision, artifact emission |
| **`apntalk/esl-replay`** | **Durable storage, deterministic reads, checkpoints, offline replay** |
| `apntalk/laravel-freeswitch-esl` | Laravel integration and operational control plane |

## Requirements

- PHP 8.2+

## Installation

```bash
composer require apntalk/esl-replay
```

## Quick start: storing artifacts

```php
use Apntalk\EslReplay\Config\ReplayConfig;
use Apntalk\EslReplay\Config\StorageConfig;
use Apntalk\EslReplay\Storage\ReplayArtifactStore;

$store = ReplayArtifactStore::make(new ReplayConfig(
    storage: new StorageConfig('/var/replay/artifacts'),
));

// $artifact implements CapturedArtifactEnvelope (emitted by esl-react)
$id = $store->write($artifact);
```

## Quick start: reading artifacts

```php
// Read from the beginning
$cursor  = $store->openCursor();
$records = $store->readFromCursor($cursor, limit: 100);

foreach ($records as $record) {
    echo "{$record->appendSequence}: {$record->artifactName}" . PHP_EOL;
    // Advance cursor to track progress
    $cursor = $cursor->advance($record->appendSequence);
}

// Look up a specific record by id
$record = $store->readById($id);
```

## Quick start: checkpoint and resume

```php
use Apntalk\EslReplay\Checkpoint\ExecutionResumeState;
use Apntalk\EslReplay\Checkpoint\FilesystemCheckpointStore;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointService;
use Apntalk\EslReplay\Config\CheckpointConfig;

$checkpointStore = FilesystemCheckpointStore::make(
    new CheckpointConfig('/var/replay/checkpoints', 'my-processor')
);
$service = new ReplayCheckpointService($checkpointStore, 'my-processor');

// At startup: resolve where to start reading
$state  = ExecutionResumeState::resolve($checkpointStore, 'my-processor');
$cursor = $state->cursor; // ReplayReadCursor::start() or saved position

// Process artifacts...
foreach ($store->readFromCursor($cursor, 100) as $record) {
    // ... process record ...
    $cursor = $cursor->advance($record->appendSequence);
    $service->save($cursor); // save progress after each record (or batch)
}
```

> **Important:** A checkpoint restores artifact-processing progress only. It does
> NOT restore a live FreeSWITCH socket, ESL session, or any runtime continuity.

## Quick start: offline replay

```php
use Apntalk\EslReplay\Config\ExecutionConfig;
use Apntalk\EslReplay\Execution\OfflineReplayExecutor;

$executor = OfflineReplayExecutor::make(
    new ExecutionConfig(dryRun: true),
    $store,
);

// Plan: inspect what would be replayed
$plan = $executor->plan($store->openCursor());
echo "Records to replay: {$plan->recordCount}" . PHP_EOL;

// Execute: dry-run produces no side effects
$result = $executor->execute($plan);
echo "Skipped (dry-run): {$result->skippedCount}" . PHP_EOL;
```

Offline replay operates only on stored artifacts. It does NOT require a live
FreeSWITCH socket.

## Safety note on re-injection

Controlled protocol re-injection is a future, optional, and higher-risk capability.
It is not part of this release. Setting `ExecutionConfig::reinjectionEnabled = true`
throws an `\InvalidArgumentException`.

When re-injection is introduced in a future release, it will be:
- explicitly configured and disabled by default
- allowlist-based (only certain artifact types)
- dry-run capable
- clearly documented as a higher-risk operating mode

## Documentation

- [Architecture](docs/architecture.md)
- [Public API](docs/public-api.md)
- [Artifact Schema](docs/artifact-schema.md)
- [Artifact Identity and Ordering](docs/artifact-identity-and-ordering.md)
- [Storage Model](docs/storage-model.md)
- [Checkpoint Model](docs/checkpoint-model.md)
- [Replay Execution](docs/replay-execution.md)
- [Retention Policy](docs/retention-policy.md)
- [Stability Policy](docs/stability-policy.md)

## License

MIT
