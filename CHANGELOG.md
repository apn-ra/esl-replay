# Changelog

All notable changes to `apntalk/esl-replay` are documented here.

## [Unreleased]

## [0.9.3-rc1] — Release Candidate

Release-candidate cut for the completed filesystem, retention, checkpoint,
checksum, and public-contract hardening work.

### Highlights

- fail-closed empty-checkpoint prune query handling with explicit opt-in for
  intentional uncheckpointed pruning
- collision-safe filesystem checkpoint filenames using deterministic hashes,
  with legacy lookup compatibility
- filesystem retention/write coordination through `artifacts.ndjson.lock`
- package-level filesystem single-writer ownership through
  `artifacts.ndjson.writer.lock`
- consumer-invoked checksum verification semantics and stable
  `OperatorIdentityKeys` contract constants
- fail-loud sequence recovery when an existing `artifacts.ndjson` cannot be opened

### Release-Facing Fixes

- stale filesystem `byteOffsetHint` past EOF no longer hides valid readable records
- filesystem retention/rewrite now fails explicitly on malformed retained input
- reinjection injector exceptions now return failed replay results with partial outcomes
- Checksum verification is documented as consumer-invoked via
  `ArtifactChecksum::verify()`; ordinary reads do not verify checksums by default
- `StorageConfig::storagePath` docs now distinguish filesystem directory paths
  from SQLite/database file paths
- Checkpoint query pruning now fails closed when a query resolves zero active
  checkpoints unless `allowEmptyCheckpointQuery: true` is passed explicitly
- Filesystem retention rewrite now coordinates with package filesystem writers
  via `artifacts.ndjson.lock` and fails closed when that lock is held
- Filesystem sequence recovery now fails explicitly when an existing
  `artifacts.ndjson` cannot be opened, avoiding accidental sequence reuse
- Filesystem write-capable stores now enforce single package writer ownership
  via `artifacts.ndjson.writer.lock`
- Published `OperatorIdentityKeys` as the stable cross-package contract for
  `replay_session_id`, `pbx_node_slug`, and `worker_session_id`
- PHPUnit suite configuration and Composer/archive metadata were cleaned up for
  release packaging

### Not In This RC

- PostgreSQL support
- non-filesystem retention backends
- live runtime/session restoration semantics

## [0.3.0] — Checkpointed Progress Recovery

- `FilesystemCheckpointStore` implementing `ReplayCheckpointStoreInterface` with atomic write semantics
- `ReplayCheckpointService` — higher-level save/load/clear checkpoint lifecycle API
- `ExecutionResumeState` — resolves fresh-start vs. resume at startup from a checkpoint store
- Integration tests for checkpoint save/load/delete, overwrite, metadata, byte-offset hint roundtrip, and restart-safe resume scenario
- `docs/checkpoint-model.md`

## [0.2.0] — Deterministic Serialization and Filesystem Durability

- `ReplayArtifactSerializer` — deterministic NDJSON serialization with schema version field
- `ArtifactChecksum` — SHA-256 integrity marker over canonical artifact fields; payload key order independent
- `StoredReplayRecordFactory` — produces `StoredReplayRecord` from `CapturedArtifactEnvelope`; manages append sequence per stream
- `NdjsonReplayWriter` — append-only NDJSON artifact persistence with exclusive file locking
- `NdjsonReplayReader` — cursor-based ordered reads; supports byte-offset hint for performance
- `FilesystemReplayArtifactStore` — combines writer and reader; recovers sequence from file on restart
- `ReplayArtifactStore::make(ReplayConfig)` — primary stable entry point
- `OfflineReplayExecutor::make(ExecutionConfig, ReplayArtifactReaderInterface)` — primary stable entry point for offline replay
- `ExecutionConfig::batchLimit` parameter added (default 500)
- Integration tests for append/read/cursor/resume/restart/checksum flows
- `phpstan/phpstan-phpunit` added to dev dependencies
- `docs/storage-model.md`, `docs/artifact-schema.md`, `docs/artifact-identity-and-ordering.md`, `docs/replay-execution.md`

## [0.1.0] — Foundation and Minimal Contracts

- Package foundation: `composer.json`, PSR-4 autoloading, PHPUnit 11, PHPStan level 8, CI (PHP 8.2 + 8.3)
- `CapturedArtifactEnvelope` — input contract interface from `apntalk/esl-react`
- `ReplayArtifactStoreInterface`, `ReplayArtifactWriterInterface`, `ReplayArtifactReaderInterface`
- `ReplayCheckpointStoreInterface`, `OfflineReplayExecutorInterface`
- Config objects: `ReplayConfig`, `StorageConfig`, `CheckpointConfig`, `ExecutionConfig`
- DTOs: `StoredReplayRecord`, `ReplayRecordId`, `ReplayReadCursor`, `ReplayCheckpoint`, `OfflineReplayPlan`, `OfflineReplayResult`
- Exceptions: `ReplayException`, `ArtifactPersistenceException`, `CheckpointException`, `CursorException`, `SerializationException`
- Unit tests for all config guards, cursor semantics, checkpoint immutability, DTO constraints
- `docs/architecture.md`, `docs/public-api.md`, `docs/stability-policy.md`, `docs/retention-policy.md`, `README.md`
