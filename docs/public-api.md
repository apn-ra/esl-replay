# Public API

This document describes the stable public surface of `apntalk/esl-replay`.

## Stability policy

Only contracts, config objects, DTOs, and entry points listed here are stable.
Everything else is internal and may change without notice.

See `docs/stability-policy.md` for the full stability contract.

## Entry points

### `ReplayArtifactStore::make(ReplayConfig $config): ReplayArtifactStoreInterface`

Creates a storage adapter from configuration. Currently supports the filesystem
and SQLite adapters.

```php
use Apntalk\EslReplay\Config\ReplayConfig;
use Apntalk\EslReplay\Config\StorageConfig;
use Apntalk\EslReplay\Storage\ReplayArtifactStore;

$store = ReplayArtifactStore::make(new ReplayConfig(
    storage: new StorageConfig('/var/replay/artifacts'),
));
```

### `OfflineReplayExecutor::make(ExecutionConfig $config, ReplayArtifactReaderInterface $reader): OfflineReplayExecutorInterface`

Creates an offline replay executor.

```php
use Apntalk\EslReplay\Config\ExecutionConfig;
use Apntalk\EslReplay\Execution\OfflineReplayExecutor;

$executor = OfflineReplayExecutor::make(
    new ExecutionConfig(dryRun: true),
    $store,
);
```

An optional third argument may be supplied:

```php
OfflineReplayExecutor::make(
    ExecutionConfig $config,
    ReplayArtifactReaderInterface $reader,
    ?ReplayHandlerRegistry $handlers = null,
): OfflineReplayExecutorInterface
```

Optional guarded re-injection arguments may also be supplied:

```php
OfflineReplayExecutor::make(
    ExecutionConfig $config,
    ReplayArtifactReaderInterface $reader,
    ?ReplayHandlerRegistry $handlers = null,
    ?ReplayInjectorInterface $injector = null,
    ?ArtifactExecutabilityClassifier $classifier = null,
): OfflineReplayExecutorInterface
```

## Stable contracts

### `ReplayArtifactStoreInterface`

Extends both writer and reader. The primary store contract.

```php
interface ReplayArtifactStoreInterface extends ReplayArtifactWriterInterface, ReplayArtifactReaderInterface {}
```

### `ReplayArtifactWriterInterface`

```php
public function write(CapturedArtifactEnvelope $artifact): ReplayRecordId;
```

### `ReplayArtifactReaderInterface`

```php
public function readById(ReplayRecordId $id): ?StoredReplayRecord;
public function readFromCursor(
    ReplayReadCursor $cursor,
    int $limit = 100,
    ?ReplayReadCriteria $criteria = null,
): array;
public function openCursor(): ReplayReadCursor;
```

### `ReplayCheckpointStoreInterface`

```php
public function save(ReplayCheckpoint $checkpoint): void;
public function load(string $key): ?ReplayCheckpoint;
public function exists(string $key): bool;
public function delete(string $key): void;
```

### `ReplayCheckpointInspectorInterface`

```php
public function find(ReplayCheckpointCriteria $criteria): array;
```

### `CheckpointCompatibilityValidator`

```php
public function assertCompatible(
    ReplayArtifactReaderInterface $reader,
    array $checkpoints,
): void;
```

### `OfflineReplayExecutorInterface`

```php
public function plan(ReplayReadCursor $from): OfflineReplayPlan;
public function execute(OfflineReplayPlan $plan): OfflineReplayResult;
```

### `ReplayRecordHandlerInterface`

```php
public function handle(StoredReplayRecord $record): ReplayHandlerResult;
```

### `ReplayInjectorInterface`

```php
public function inject(ReplayExecutionCandidate $candidate): InjectionResult;
```

## Stable config objects

| Class | Description |
|---|---|
| `ReplayConfig` | Top-level config: composes StorageConfig, CheckpointConfig, ExecutionConfig |
| `StorageConfig` | Storage path and adapter selection (`filesystem`, `sqlite`, or compatibility alias `database`) |
| `CheckpointConfig` | Checkpoint storage path and key |
| `ExecutionConfig` | Dry-run flag, guarded re-injection allowlist, batch limit |

## Stable DTOs

| Class | Description |
|---|---|
| `StoredReplayRecord` | The persisted durable record |
| `ReplayRecordId` | Immutable UUID-v4 storage record identifier |
| `ReplayReadCursor` | Immutable cursor tracking last consumed append sequence |
| `ReplayReadCriteria` | Immutable bounded filter criteria for append-ordered reads |
| `ReplayCheckpoint` | Persisted artifact-processing progress checkpoint |
| `ReplayCheckpointCriteria` | Immutable bounded checkpoint lookup criteria |
| `ReplayCheckpointReference` | First-class checkpoint write reference with stable identity anchors |
| `OfflineReplayPlan` | Planned offline replay over a set of stored records |
| `OfflineReplayResult` | Result of an executed offline replay plan |
| `ReplayHandlerRegistry` | Immutable exact-match artifact-name handler mapping |
| `ReplayHandlerResult` | Structured handler outcome for offline replay dispatch |
| `PrunePolicy` | Immutable retention policy for explicit pruning |
| `RetentionPlan` | Explicit pruning plan over an ordered retained stream |
| `RetentionResult` | Result of an applied pruning operation |
| `ReplayExecutionCandidate` | Execution-facing projection derived from a stored replay record |
| `InjectionGuard` | Explicit reinjection allowlist guard |
| `InjectionResult` | Structured result of one guarded re-injection attempt |

### `ReplayReadCriteria`

```php
new ReplayReadCriteria(
    capturedFrom: ?DateTimeImmutable,
    capturedUntil: ?DateTimeImmutable,
    artifactName: ?string,
    jobUuid: ?string,
    replaySessionId: ?string,
    pbxNodeSlug: ?string,
    workerSessionId: ?string,
    sessionId: ?string,
    connectionGeneration: ?string,
);
```

This criteria object is intentionally bounded. It supports inclusive capture-time
windows and exact matching on a small set of stored record fields, including
`replay_session_id` and selected runtime metadata fields. It is not a general
query DSL.

### `ReplayCheckpointRepository`

```php
$repository = new ReplayCheckpointRepository($checkpointStore);

$repository->save(
    new ReplayCheckpointReference(
        key: 'worker-a',
        replaySessionId: 'replay-session-001',
        workerSessionId: 'worker-a',
    ),
    $cursor,
);

$matches = $repository->find(new ReplayCheckpointCriteria(
    replaySessionId: 'replay-session-001',
    workerSessionId: 'worker-a',
));
```

This repository keeps checkpoint write semantics explicit while exposing a
bounded operational lookup surface over stable identity anchors.

### `ReplayCheckpointService` and `ExecutionResumeState`

These helper types are also part of the supported checkpoint surface:

```php
$service = new ReplayCheckpointService($checkpointStore, 'my-processor');
$service->save($cursor);

$state = ExecutionResumeState::resolve($checkpointStore, 'my-processor');
```

They remain narrowly scoped to persisted-artifact progress save/load/resume and
do not imply live-session recovery semantics.

## Stable retention surface

### `CheckpointAwarePruner`

```php
$pruner = new CheckpointAwarePruner('/var/replay/artifacts');

$plan = $pruner->plan($activeCheckpoints, new PrunePolicy(
    maxRecordAge: new DateInterval('P7D'),
    maxStreamBytes: 10_000_000,
    protectedRecordCount: 500,
));

$result = $pruner->prune($activeCheckpoints, $policy);
```

This retention surface is conservative and filesystem-backed in the current
release. It prunes only an ordered prefix, validates checkpoint compatibility
before pruning, never silently invalidates active checkpoints, and fails
explicitly if malformed retained input is discovered during rewrite planning.

It also supports bounded checkpoint-driven planning/pruning by resolving active
checkpoints through `ReplayCheckpointInspectorInterface` plus
`ReplayCheckpointCriteria`.

## Stable guarded re-injection surface

- `ReplayInjectorInterface`
- `ReplayExecutionCandidate`
- `InjectionGuard`
- `ArtifactExecutabilityClassifier`
- `InjectionResult`

Guarded re-injection is optional, disabled by default, and separately documented
as a higher-risk mode. It does not change the package’s primary offline replay identity.

PostgreSQL is not part of the current stable surface.

## Input contract

### `CapturedArtifactEnvelope`

The interface that artifacts emitted by `apntalk/esl-react` must implement.
This is the boundary between the live runtime layer and the durable storage layer.

## Not part of the stable public API

These are internal and may change:

- `NdjsonReplayWriter` / `NdjsonReplayReader` / `FilesystemReplayArtifactStore`
- `SqliteReplayArtifactStore`
- `ReplayArtifactSerializer` / `ArtifactChecksum` / `StoredReplayRecordFactory`
- `FilesystemCheckpointStore`
- Any internal criteria-matching helpers or adapter indexing internals
- Future retention worker orchestration beyond the current explicit pruner
- Future transport-specific injector implementations beyond the public injector contract
