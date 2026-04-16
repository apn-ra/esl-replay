# Public API

This document describes the stable public surface of `apntalk/esl-replay`.

## Stability policy

Only contracts, config objects, DTOs, and entry points listed here are stable.
Everything else is internal and may change without notice.

See `docs/stability-policy.md` for the full stability contract.

## Entry points

### `ReplayArtifactStore::make(ReplayConfig $config): ReplayArtifactStoreInterface`

Creates a storage adapter from configuration. Currently supports the filesystem adapter.

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
public function readFromCursor(ReplayReadCursor $cursor, int $limit = 100): array;
public function openCursor(): ReplayReadCursor;
```

### `ReplayCheckpointStoreInterface`

```php
public function save(ReplayCheckpoint $checkpoint): void;
public function load(string $key): ?ReplayCheckpoint;
public function exists(string $key): bool;
public function delete(string $key): void;
```

### `OfflineReplayExecutorInterface`

```php
public function plan(ReplayReadCursor $from): OfflineReplayPlan;
public function execute(OfflineReplayPlan $plan): OfflineReplayResult;
```

## Stable config objects

| Class | Description |
|---|---|
| `ReplayConfig` | Top-level config: composes StorageConfig, CheckpointConfig, ExecutionConfig |
| `StorageConfig` | Storage path and adapter selection |
| `CheckpointConfig` | Checkpoint storage path and key |
| `ExecutionConfig` | Dry-run flag, batch limit |

## Stable DTOs

| Class | Description |
|---|---|
| `StoredReplayRecord` | The persisted durable record |
| `ReplayRecordId` | Immutable UUID-v4 storage record identifier |
| `ReplayReadCursor` | Immutable cursor tracking last consumed append sequence |
| `ReplayCheckpoint` | Persisted artifact-processing progress checkpoint |
| `OfflineReplayPlan` | Planned offline replay over a set of stored records |
| `OfflineReplayResult` | Result of an executed offline replay plan |

## Input contract

### `CapturedArtifactEnvelope`

The interface that artifacts emitted by `apntalk/esl-react` must implement.
This is the boundary between the live runtime layer and the durable storage layer.

## Not part of the stable public API

These are internal and may change:

- `NdjsonReplayWriter` / `NdjsonReplayReader` / `FilesystemReplayArtifactStore`
- `ReplayArtifactSerializer` / `ArtifactChecksum` / `StoredReplayRecordFactory`
- `FilesystemCheckpointStore` / `ReplayCheckpointService` / `ExecutionResumeState`
- Query DSL internals (not yet implemented)
- Retention worker internals (not yet implemented)
- Any re-injection machinery (not yet implemented)
