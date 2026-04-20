# Stability Policy

## Stable public API

The following are stable and will not change in a breaking way within a minor version:

**Contracts:**
- `ReplayArtifactStoreInterface`
- `ReplayArtifactWriterInterface`
- `ReplayArtifactReaderInterface`
- `ReplayCheckpointStoreInterface`
- `ReplayCheckpointInspectorInterface`
- `OfflineReplayExecutorInterface`
- `ReplayRecordHandlerInterface`
- `ReplayInjectorInterface`

**Config objects:**
- `ReplayConfig`
- `StorageConfig`
- `CheckpointConfig`
- `ExecutionConfig`

**DTOs:**
- `StoredReplayRecord`
- `ReplayRecordId`
- `ReplayReadCursor`
- `ReplayReadCriteria`
- `ReplayCheckpoint`
- `ReplayCheckpointCriteria`
- `ReplayCheckpointReference`
- `OfflineReplayPlan`
- `OfflineReplayResult`
- `ReplayHandlerRegistry`
- `ReplayHandlerResult`
- `PrunePolicy`
- `RetentionPlan`
- `RetentionResult`
- `ReplayExecutionCandidate`
- `InjectionGuard`
- `InjectionResult`

**Entry points:**
- `ReplayArtifactStore::make(ReplayConfig $config): ReplayArtifactStoreInterface`
- `OfflineReplayExecutor::make(ExecutionConfig $config, ReplayArtifactReaderInterface $reader): OfflineReplayExecutorInterface`
- `FilesystemCheckpointStore::make(CheckpointConfig $config): FilesystemCheckpointStore`
- `CheckpointAwarePruner`
- `CheckpointCompatibilityValidator`
- `ReplayCheckpointRepository`
- `ReplayCheckpointService`
- `ExecutionResumeState`

**Input contract:**
- `CapturedArtifactEnvelope`
- `OperatorIdentityKeys`

**Utilities:**
- `ArtifactChecksum`

## Internal / provisional

These are internal and may change without notice:

- `NdjsonReplayWriter` / `NdjsonReplayReader` / `FilesystemReplayArtifactStore`
- `SqliteReplayArtifactStore`
- `ReplayArtifactSerializer` / `StoredReplayRecordFactory`
- Any future query DSL internals
- Any future retention worker internals
- Any future re-injection machinery

## Serialized schema stability

The NDJSON schema version (`schema_version: 1`) is stable. A reader that encounters
`schema_version: 2` or higher must fail explicitly via `SerializationException`.

Future schema changes must bump `schema_version` and provide an explicit migration path.

## API freeze audit

The current stable public surface has been audited against code, tests, and docs.
This audit covers:
- filesystem and SQLite append-ordered storage parity
- bounded reader criteria
- checkpoint save/load/restart semantics
- offline replay planning, handler dispatch, and guarded re-injection
- explicit filesystem retention coordination

Concrete adapter classes remain internal. The stable API continues to be the
contracts, DTOs, config objects, and documented entry points listed above.

## Implementation roadmap

| Milestone | Content |
|---|---|
| 0.1.0 | Foundation: contracts, config, DTOs, tooling |
| 0.2.0 | Deterministic serialization + filesystem NDJSON adapter |
| 0.3.0 | Checkpointed progress recovery |
| 0.4.0 | Bounded reader enrichment |
| 0.5.0 | Offline replay — handler dispatch |
| 0.6.0 | Retention coordination |
| 0.7.0 | SQL adapters |
| 0.8.0 | Optional controlled re-injection |
| 0.9.0 | Hardening |
| 1.0.0 | Stable replay platform (planned) |

## RC posture

The current release-cut posture is `v0.9.3-rc1`, not a final patch release,
because filesystem, retention, checkpoint, checksum, and public-contract
hardening changed late in cycle and should ship through an RC first.

## Breaking change policy

Before 1.0.0:
- Minor version bumps may add new fields to DTOs (additive changes)
- Minor version bumps may add new stable contracts
- Patch version bumps are bug fixes and documentation corrections only
- Breaking changes to stable contracts require a major version bump

After 1.0.0: full semantic versioning.
