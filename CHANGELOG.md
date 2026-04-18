# Changelog

All notable changes to `apntalk/esl-replay` are documented here.

## [Unreleased]

## [0.9.0-rc1] — Release Candidate

Release-candidate cut for the current audited replay platform surface.

Highlights:
- deterministic artifact storage and append-ordered reads
- bounded reader filtering by time window, artifact name, job UUID, replay session,
  PBX node, worker session, session id, and connection generation
- checkpointed persisted-artifact progress recovery
- handler-driven offline replay
- filesystem-backed retention coordination
- SQLite contract parity with the filesystem store contract
- guarded optional re-injection as a secondary, higher-risk mode
- hardening plus aggressive chaos coverage across corruption, restart, pruning, and guard-policy paths

Late-cycle hostile-path fixes included in this RC:
- stale filesystem `byteOffsetHint` past EOF no longer hides valid readable records
- filesystem retention/rewrite now fails explicitly on malformed retained input
- reinjection injector exceptions now return failed replay results with partial outcomes

Not in this RC:
- PostgreSQL support
- non-filesystem retention backends
- live runtime/session restoration semantics

- Phase 7: explicit offline replay handler dispatch via `ReplayRecordHandlerInterface`
  and `ReplayHandlerRegistry`
- Dry-run replay reporting now indicates whether a matching handler would be
  dispatched without invoking handlers
- Handler execution failures now return failed `OfflineReplayResult` values
  instead of silently succeeding
- Phase 8: explicit filesystem retention coordination via `CheckpointAwarePruner`,
  `PrunePolicy`, `RetentionPlan`, and `RetentionResult`
- Active checkpoint compatibility is now validated before pruning via
  `CheckpointCompatibilityValidator`
- Retention now supports conservative age- and size-based ordered-prefix pruning
  with protected record windows
- Phase 9: SQLite replay artifact store with the same append-order, cursor, and
  bounded-reader semantics as the filesystem adapter
- Added cross-adapter contract tests covering filesystem and SQLite parity
- Phase 10: guarded optional re-injection via `ReplayInjectorInterface`,
  `ReplayExecutionCandidate`, `InjectionGuard`, `ArtifactExecutabilityClassifier`,
  and `InjectionResult`
- Re-injection is now explicit, allowlist-based, dry-run capable, and rejects
  observational artifacts clearly
- Phase 11: hardening and API freeze audit with large-stream adapter coverage,
  checkpoint stress coverage, offline replay determinism checks, and explicit
  SQLite corruption failure coverage

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
