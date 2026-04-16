# Changelog

All notable changes to `apntalk/esl-replay` are documented here.

## [Unreleased]

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
