# Stability Policy

## Stable public API

The following are stable and will not change in a breaking way within a minor version:

**Contracts:**
- `ReplayArtifactStoreInterface`
- `ReplayArtifactWriterInterface`
- `ReplayArtifactReaderInterface`
- `ReplayCheckpointStoreInterface`
- `OfflineReplayExecutorInterface`

**Config objects:**
- `ReplayConfig`
- `StorageConfig`
- `CheckpointConfig`
- `ExecutionConfig`

**DTOs:**
- `StoredReplayRecord`
- `ReplayRecordId`
- `ReplayReadCursor`
- `ReplayCheckpoint`
- `OfflineReplayPlan`
- `OfflineReplayResult`

**Entry points:**
- `ReplayArtifactStore::make(ReplayConfig $config): ReplayArtifactStoreInterface`
- `OfflineReplayExecutor::make(ExecutionConfig $config, ReplayArtifactReaderInterface $reader): OfflineReplayExecutorInterface`

**Input contract:**
- `CapturedArtifactEnvelope`

## Internal / provisional

These are internal and may change without notice:

- `NdjsonReplayWriter` / `NdjsonReplayReader` / `FilesystemReplayArtifactStore`
- `ReplayArtifactSerializer` / `ArtifactChecksum` / `StoredReplayRecordFactory`
- `FilesystemCheckpointStore` / `ReplayCheckpointService` / `ExecutionResumeState`
- Any future query DSL internals
- Any future retention worker internals
- Any future re-injection machinery

## Serialized schema stability

The NDJSON schema version (`schema_version: 1`) is stable. A reader that encounters
`schema_version: 2` or higher must fail explicitly via `SerializationException`.

Future schema changes must bump `schema_version` and provide an explicit migration path.

## Implementation roadmap

| Milestone | Content |
|---|---|
| 0.1.0 | Foundation: contracts, config, DTOs, tooling |
| 0.2.0 | Deterministic serialization + filesystem NDJSON adapter |
| 0.3.0 | Checkpointed progress recovery |
| 0.4.0 | Bounded reader enrichment (planned) |
| 0.5.0 | Offline replay — handler dispatch (planned) |
| 0.6.0 | Retention coordination (planned) |
| 0.7.0 | SQL adapters (planned) |
| 0.8.0 | Optional controlled re-injection (planned) |
| 0.9.0 | Hardening (planned) |
| 1.0.0 | Stable replay platform (planned) |

## Breaking change policy

Before 1.0.0:
- Minor version bumps may add new fields to DTOs (additive changes)
- Minor version bumps may add new stable contracts
- Patch version bumps are bug fixes and documentation corrections only
- Breaking changes to stable contracts require a major version bump

After 1.0.0: full semantic versioning.
