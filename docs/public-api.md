# Public API

This document describes the stable public surface of `apntalk/esl-replay`.

## Stability policy

Only contracts, config objects, DTOs, documented utilities, and entry points
listed here are stable. Everything else is internal and may change without
notice.

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

### `RecoveryEvidenceEngine::make(ReplayArtifactReaderInterface $reader): RecoveryEvidenceEngine`

Creates the bounded recovery/evidence reconstruction engine.

```php
use Apntalk\EslReplay\Recovery\RecoveryEvidenceEngine;

$engine = RecoveryEvidenceEngine::make($store);
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

### `FilesystemCheckpointStore`

`FilesystemCheckpointStore` is the supported concrete checkpoint store in the
current release. It implements both `ReplayCheckpointStoreInterface` and
`ReplayCheckpointInspectorInterface`.

```php
$checkpointStore = FilesystemCheckpointStore::make(
    new CheckpointConfig('/var/replay/checkpoints', 'my-processor')
);
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

### `CheckpointReconstructionWindowResolver`

```php
$resolver = new CheckpointReconstructionWindowResolver($store);
$window = $resolver->resolve($checkpoint);
```

This helper resolves bounded reconstruction windows from persisted checkpoints
and fails closed when checkpoint identity metadata contradicts the next stored
artifacts visible in that window.

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
| `StorageConfig` | Storage path and adapter selection. Filesystem uses a directory path; SQLite/database uses a database file path. |
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
| `ReconstructionWindow` | Bounded append-ordered reconstruction window over stored artifacts |
| `RecoveryManifest` | Deterministic identity and verdict for one evidence bundle |
| `RecoveryGenerationObservation` | One reconstructed recovery-generation observation in append order |
| `ReconstructionVerdict` | Deterministic reconstruction posture and issue set |
| `ReconstructionIssue` | One fail-closed insufficiency, ambiguity, or mismatch issue |
| `RuntimeContinuitySnapshot` | Reconstructed bounded runtime continuity posture |
| `OperationRecoveryRecord` | Reconstructed operation lifecycle evidence |
| `TerminalPublicationEvidenceRecord` | Bounded terminal-publication evidence |
| `LifecycleSemanticEvidenceRecord` | Bounded lifecycle-semantic evidence |
| `EvidenceRecordReference` | Provenance reference to a stored replay record used in a bundle |
| `EvidenceBundle` | Deterministic machine-readable recovery/evidence bundle |
| `ScenarioExpectation` | Generic scenario expectation input for comparisons |
| `ExpectedOperationLifecycle` | Expected operation lifecycle input |
| `ExpectedTerminalPublication` | Expected terminal-publication input |
| `ExpectedLifecycleSemantic` | Expected lifecycle-semantic input |
| `ScenarioComparisonResult` | Deterministic bundle-vs-expectation comparison result |

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

`OperatorIdentityKeys` publishes the stable key names shared with artifact
producers and checkpoint metadata:
- `OperatorIdentityKeys::REPLAY_SESSION_ID` = `replay_session_id`
- `OperatorIdentityKeys::PBX_NODE_SLUG` = `pbx_node_slug`
- `OperatorIdentityKeys::WORKER_SESSION_ID` = `worker_session_id`

`replay_session_id` is expected in `correlation_ids` and may be read from
`runtime_flags` as a fallback for derived inspection. `pbx_node_slug` and
`worker_session_id` are expected in `runtime_flags`.

### `RecoveryMetadataKeys`

`RecoveryMetadataKeys` publishes the stable additive keys used by the recovery
and evidence engine when newer `esl-react` releases emit richer runtime truth
through stored metadata rather than hard package types. Current keys include:

- `recovery_generation_id`
- `retry_posture`
- `drain_posture`
- `reconstruction_posture`
- `replay_continuity_posture`
- `operation_id`
- `operation_kind`
- `operation_state`
- `bgapi_job_uuid`
- `terminal_publication_id`
- `terminal_publication_status`
- `lifecycle_semantic`

### `ArtifactChecksum`

`ArtifactChecksum::verify(StoredReplayRecord $record): bool` is the supported
consumer-invoked checksum verification helper. Ordinary `readFromCursor()` and
`readById()` calls do not verify checksums automatically. The checksum covers
only `artifact_version`, `artifact_name`, `capture_timestamp`, and `payload`;
it excludes storage metadata, operator identity metadata, and derived fields.

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

`ReplayCheckpointReference` and `ReplayCheckpointCriteria` now also support the
additive identity anchor `recoveryGenerationId`, persisted as
`recovery_generation_id` in checkpoint metadata.

### `ReplayCheckpointService`, `ExecutionResumeState`, and `ReplayCheckpointRepository`

These helpers are also part of the supported checkpoint surface built around
`FilesystemCheckpointStore` plus the stable checkpoint contracts:

```php
$service = new ReplayCheckpointService($checkpointStore, 'my-processor');
$service->save($cursor);

$state = ExecutionResumeState::resolve($checkpointStore, 'my-processor');

$repository = new ReplayCheckpointRepository($checkpointStore);
```

They remain narrowly scoped to persisted-artifact progress save/load/resume and
do not imply live-session recovery semantics.

## Stable recovery/evidence surface

`RecoveryEvidenceEngine` is the stable additive recovery/evidence surface:

- `reconstruct(ReconstructionWindow $window): EvidenceBundle`
- `compareScenario(EvidenceBundle $bundle, ScenarioExpectation $expectation): ScenarioComparisonResult`
- `exportBundle(EvidenceBundle $bundle): string`
- `exportComparison(ScenarioComparisonResult $comparison): string`

These APIs operate only on stored artifacts and deterministic projections.
They do not become a live recovery or reconnect API.

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
Filesystem pruning also coordinates with package filesystem writers through the
`artifacts.ndjson.lock` sibling lock and fails closed if that lock is already
held.

Filesystem write-capable stores also enforce single package writer ownership
through `artifacts.ndjson.writer.lock`. A second package filesystem writer/store
for the same storage path fails closed while the first owner is active.

It also supports bounded checkpoint-driven planning/pruning by resolving active
checkpoints through `ReplayCheckpointInspectorInterface` plus
`ReplayCheckpointCriteria`. A checkpoint query that resolves no checkpoints
fails closed unless `allowEmptyCheckpointQuery: true` is passed explicitly for
an intentional uncheckpointed prune.

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

### `OperatorIdentityKeys`

Stable cross-package constants for replay operator identity metadata:
- `REPLAY_SESSION_ID` maps to `replay_session_id`
- `PBX_NODE_SLUG` maps to `pbx_node_slug`
- `WORKER_SESSION_ID` maps to `worker_session_id`

Use these keys when producing or inspecting `correlation_ids`, `runtime_flags`,
and checkpoint metadata that should participate in bounded replay inspection.

## Not part of the stable public API

These are internal and may change:

- `NdjsonReplayWriter` / `NdjsonReplayReader` / `FilesystemReplayArtifactStore`
- `SqliteReplayArtifactStore`
- `ReplayArtifactSerializer` / `StoredReplayRecordFactory`
- Any internal criteria-matching helpers or adapter indexing internals
- Future retention worker orchestration beyond the current explicit pruner
- Future transport-specific injector implementations beyond the public injector contract
