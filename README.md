# apntalk/esl-replay

Durable replay artifact platform for FreeSWITCH ESL runtime output.

## What this package is

`apntalk/esl-replay` provides durable storage, deterministic reads, restart-safe
progress recovery, offline replay, and bounded recovery/evidence reconstruction
for FreeSWITCH ESL artifacts emitted by `apntalk/esl-react`.

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
| **`apntalk/esl-replay`** | **Durable storage, deterministic reads, checkpoints, offline replay, recovery/evidence reconstruction** |
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

`StorageConfig` currently supports:
- `filesystem` with a storage directory path
- `sqlite` with a SQLite database file path
- `database` as a compatibility alias for the current SQLite-backed adapter

The same `storagePath` constructor argument is interpreted by adapter: a
filesystem store expects a directory, while the SQLite-backed adapters expect a
database file path.

For this release, SQLite preserves the same read/order contract as the
filesystem adapter for a single active writer epoch. If a second long-lived
SQLite store instance writes against the same database without reopening after
another writer has advanced the stream, the write fails explicitly.

PostgreSQL is not implemented in this release.

## Quick start: reading artifacts

```php
use Apntalk\EslReplay\Read\ReplayReadCriteria;

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

// Apply conservative bounded filters while preserving append order
$criteria = new ReplayReadCriteria(
    artifactName: 'event.raw',
    replaySessionId: 'replay-session-001',
    pbxNodeSlug: 'pbx-a',
);

$filtered = $store->readFromCursor($store->openCursor(), limit: 100, criteria: $criteria);
```

Bounded reader filtering currently supports inclusive capture-time windows plus
exact matching on `artifactName`, `jobUuid`, `replaySessionId`,
`pbxNodeSlug`, `workerSessionId`, `sessionId`, and `connectionGeneration`.
This is not a general query engine; filtered reads still return records in
append-sequence order within the adapter stream.

On the filesystem path, ordinary reads intentionally skip malformed persisted
lines so valid stored records remain readable after a partial or interrupted tail
write. Retention/rewrite flows are stricter and fail explicitly if malformed
retained input is discovered.

## Quick start: checkpoint and resume

```php
use Apntalk\EslReplay\Checkpoint\ExecutionResumeState;
use Apntalk\EslReplay\Checkpoint\FilesystemCheckpointStore;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointReference;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointRepository;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointService;
use Apntalk\EslReplay\Config\CheckpointConfig;

$checkpointStore = FilesystemCheckpointStore::make(
    new CheckpointConfig('/var/replay/checkpoints', 'my-processor')
);
$service = new ReplayCheckpointService($checkpointStore, 'my-processor');
$repository = new ReplayCheckpointRepository($checkpointStore);

// At startup: resolve where to start reading
$state  = ExecutionResumeState::resolve($checkpointStore, 'my-processor');
$cursor = $state->cursor; // ReplayReadCursor::start() or saved position

// Process artifacts...
foreach ($store->readFromCursor($cursor, 100) as $record) {
    // ... process record ...
    $cursor = $cursor->advance($record->appendSequence);
    $service->save($cursor); // save progress after each record (or batch)
}

// Save a checkpoint with explicit operational identity anchors
$repository->save(
    new ReplayCheckpointReference(
        key: 'worker-a',
        replaySessionId: 'replay-session-001',
        jobUuid: 'job-123',
        pbxNodeSlug: 'pbx-a',
        workerSessionId: 'worker-a',
    ),
    $cursor,
);

// Later: bounded checkpoint lookup for drain/resume workflows
$matches = $repository->find(new ReplayCheckpointCriteria(
    replaySessionId: 'replay-session-001',
    workerSessionId: 'worker-a',
));
```

> **Important:** A checkpoint restores artifact-processing progress only. It does
> NOT restore a live FreeSWITCH socket, ESL session, or any runtime continuity.

`FilesystemCheckpointStore`, `ReplayCheckpointService`,
`ExecutionResumeState`, and `ReplayCheckpointRepository` are the supported
checkpoint path in this release.

## Quick start: offline replay

```php
use Apntalk\EslReplay\Config\ExecutionConfig;
use Apntalk\EslReplay\Execution\OfflineReplayExecutor;
use Apntalk\EslReplay\Execution\ReplayExecutionCandidate;
use Apntalk\EslReplay\Execution\ReplayHandlerRegistry;
use Apntalk\EslReplay\Execution\InjectionResult;
use Apntalk\EslReplay\Contracts\ReplayInjectorInterface;

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

// Optional bounded handler dispatch in non-dry-run mode
$executorWithHandlers = OfflineReplayExecutor::make(
    new ExecutionConfig(dryRun: false),
    $store,
    new ReplayHandlerRegistry([
        'api.dispatch' => $myApiDispatchHandler,
    ]),
);

// Optional guarded re-injection remains explicit and caller-supplied.
$injector = new class implements ReplayInjectorInterface {
    public function inject(ReplayExecutionCandidate $candidate): InjectionResult
    {
        // Dispatch through caller-owned transport here.
        return new InjectionResult('injected');
    }
};

$reinjectionExecutor = OfflineReplayExecutor::make(
    new ExecutionConfig(
        dryRun: true,
        reinjectionEnabled: true,
        reinjectionArtifactAllowlist: ['api.dispatch'],
    ),
    $store,
    null,
    $injector,
);
```

Offline replay operates only on stored artifacts. It does NOT require a live
FreeSWITCH socket. Handler dispatch is explicit and bounded by exact artifact
name matching; unhandled records remain observational.

## Quick start: recovery evidence reconstruction

```php
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointReference;
use Apntalk\EslReplay\Recovery\CheckpointReconstructionWindowResolver;
use Apntalk\EslReplay\Recovery\RecoveryEvidenceEngine;

$repository = new ReplayCheckpointRepository($checkpointStore);
$checkpoint = $repository->save(
    new ReplayCheckpointReference(
        key: 'worker-a',
        replaySessionId: 'replay-session-001',
        recoveryGenerationId: 'generation-7',
    ),
    $cursor,
);

$resolver = new CheckpointReconstructionWindowResolver($store);
$window = $resolver->resolve($checkpoint);

$engine = RecoveryEvidenceEngine::make($store);
$bundle = $engine->reconstruct($window);

echo $bundle->manifest->bundleId . PHP_EOL;
echo $bundle->manifest->verdict->posture . PHP_EOL;
```

Recovery/evidence reconstruction:
- consumes stored artifacts only
- preserves append-order semantics
- reconstructs bounded runtime truth from stored payload/runtime metadata
- emits deterministic machine-readable bundles and scenario comparisons
- fails closed when stored artifacts are insufficient to support a bounded claim

It does **not** restore a live ESL socket, live runtime supervision state, or
session continuity after restart.

## Safety note on re-injection

Controlled protocol re-injection is optional, disabled by default, and higher risk
than ordinary offline replay. It now requires all of the following:
- `ExecutionConfig::reinjectionEnabled = true`
- an explicit `reinjectionArtifactAllowlist`
- a caller-supplied `ReplayInjectorInterface`

Only allowlisted executable artifact types currently become reinjection candidates:
- `api.dispatch`
- `bgapi.dispatch`

Observational artifacts remain non-injectable by default. Dry-run remains safe:
it reports what would be reinjected without invoking the injector.

## Quick start: filesystem retention

```php
use Apntalk\EslReplay\Checkpoint\FilesystemCheckpointStore;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria;
use Apntalk\EslReplay\Config\CheckpointConfig;
use Apntalk\EslReplay\Retention\CheckpointAwarePruner;
use Apntalk\EslReplay\Retention\PrunePolicy;

$checkpointStore = FilesystemCheckpointStore::make(
    new CheckpointConfig('/var/replay/checkpoints', 'my-processor')
);

$pruner = new CheckpointAwarePruner('/var/replay/artifacts');
$policy = new PrunePolicy(
    maxRecordAge: new DateInterval('P7D'),
    maxStreamBytes: 10_000_000,
    protectedRecordCount: 500,
);

// Plan first: inspect what would be pruned.
$plan = $pruner->planForCheckpointQuery(
    $checkpointStore,
    new ReplayCheckpointCriteria(
        replaySessionId: 'replay-session-001',
        workerSessionId: 'my-processor',
        limit: 100,
    ),
    $policy,
);

// Apply explicitly after reviewing the plan.
$result = $pruner->pruneForCheckpointQuery(
    $checkpointStore,
    new ReplayCheckpointCriteria(
        replaySessionId: 'replay-session-001',
        workerSessionId: 'my-processor',
        limit: 100,
    ),
    $policy,
);
```

Retention is filesystem-backed in this release. It prunes only an ordered prefix,
validates active checkpoint compatibility before pruning, preserves protected
record windows, and fails explicitly on malformed retained input. Checkpoint
query pruning fails closed when the query resolves no active checkpoints; pass
`allowEmptyCheckpointQuery: true` only when an uncheckpointed prune is
intentional.

## Opt-in live verification

For RC validation, pre-release verification, or manual support checks against a
real FreeSWITCH PBX, use the opt-in live harness documented in
[docs/live-testing.md](docs/live-testing.md).

It is intentionally separate from the default PHPUnit and CI flow. It reads
live ESL credentials from `.env.live.local`, validates the replay package
surface using live-derived artifacts, and must not expose secrets from that
file.

## Documentation

- [Architecture](docs/architecture.md)
- [Public API](docs/public-api.md)
- [Artifact Schema](docs/artifact-schema.md)
- [Artifact Identity and Ordering](docs/artifact-identity-and-ordering.md)
- [Storage Model](docs/storage-model.md)
- [Checkpoint Model](docs/checkpoint-model.md)
- [Live Testing](docs/live-testing.md)
- [Replay Execution](docs/replay-execution.md)
- [Retention Policy](docs/retention-policy.md)
- [Stability Policy](docs/stability-policy.md)

## License

MIT
