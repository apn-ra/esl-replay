# Implementation Progress

## Current status

- Completed phases:
  - Phase 1 — foundation and minimal contracts
  - Phase 2 — deterministic serialization
  - Phase 3 — filesystem durability and checkpointed progress groundwork
  - Phase 4 — filesystem durability and cursor reads
  - Phase 5 — checkpoints and restart-safe progress recovery
  - Phase 6 — bounded reader enrichment
  - Phase 7 — offline replay handler dispatch
  - Phase 8 — retention coordination
  - Phase 9 — SQL adapters
  - Phase 10 — optional controlled re-injection
  - Phase 11 — hardening and API freeze
  - Phase 12 — recovery/evidence engine
- Current phase:
  - None — the current post-hardening roadmap track is implemented in the repository state
- Remaining phases:
  - None

## Key risks

- Expanding execution into a broad workflow engine instead of bounded offline replay
- Breaking append-order or checkpoint semantics while adding pruning or alternate adapters
- Allowing re-injection to become implicit instead of explicit, guarded, and allowlist-based
- Overstating adapter parity or API stability before tests and docs prove it

## Last completed phase

### Phase 12 — recovery/evidence engine

- Added deterministic recovery/evidence reconstruction over stored artifacts via:
  - `RecoveryEvidenceEngine`
  - `CheckpointReconstructionWindowResolver`
  - additive recovery/evidence DTOs and deterministic JSON export surfaces
- Added bounded reconstruction for richer runtime-truth metadata carried in:
  - `payload`
  - `runtime_flags`
  - `correlation_ids`
  - checkpoint metadata
- Added generic SC19-style scenario comparison over:
  - recovery generation sequence
  - replay continuity posture
  - operation lifecycle
  - drain/retry posture
  - terminal-publication evidence
  - lifecycle-semantic evidence
- Preserved `schema_version: 1`, append-order semantics, checkpoint boundary, and
  stable existing entry points
- Added unit, contract, and integration coverage across filesystem and SQLite
- Acceptance result:
  - Passed
- Blockers:
  - None

### Phase 11 — hardening and API freeze

- Completed hardening and API freeze audit for the current release surface
- Confirmed deterministic storage/read behavior, checkpoint progress recovery,
  offline replay, guarded re-injection, retention coordination, and SQLite
  parity remain within package scope
- Added release-facing hardening for empty-checkpoint prune safety, checkpoint
  filename collision safety, filesystem retention/write coordination, checksum
  semantics, operator identity keys, unreadable filesystem recovery, and
  filesystem writer ownership
- Updated tests and docs to match implemented truth
- Acceptance result:
  - Passed
- Blockers:
  - None

## Next work

1. Validate release-truth posture for the additive recovery/evidence surface before `1.0.0`.
2. Current stable release after recovery/evidence implementation is `v0.9.4`.

## Phase history

### Phase 6 — bounded reader enrichment

- Implemented immutable `ReplayReadCriteria`
- Extended ordered reads to support conservative filtering by:
  - inclusive capture-time window
  - artifact name
  - job UUID
  - session identifier
  - connection generation
- Preserved append-order and cursor semantics
- Added filesystem integration tests and criteria unit tests
- Updated reader and ordering docs to implemented truth

### Phase 7 — offline replay handler dispatch

- Implemented explicit artifact-name-based handler dispatch through:
  - `ReplayRecordHandlerInterface`
  - `ReplayHandlerRegistry`
  - `ReplayHandlerResult`
- Kept dry-run side-effect free while reporting whether a handler would run
- Preserved observational behavior for unhandled artifact types
- Added failure reporting for thrown handler exceptions
- Updated replay execution docs, public API docs, README, stability policy, and changelog
- Files changed:
  - `src/Contracts/ReplayRecordHandlerInterface.php`
  - `src/Execution/ReplayHandlerRegistry.php`
  - `src/Execution/ReplayHandlerResult.php`
  - `src/Execution/OfflineReplayExecutor.php`
  - `tests/Fixtures/FakeReplayRecordHandler.php`
  - `tests/Integration/OfflineReplayExecutorTest.php`
  - `tests/Unit/Execution/ReplayHandlerRegistryTest.php`
  - `tests/Unit/Execution/ReplayHandlerResultTest.php`
  - `README.md`
  - `docs/public-api.md`
  - `docs/replay-execution.md`
  - `docs/stability-policy.md`
  - `CHANGELOG.md`
- Tests run:
  - `vendor/bin/phpunit tests/Integration/OfflineReplayExecutorTest.php tests/Unit/Execution/ReplayHandlerRegistryTest.php tests/Unit/Execution/ReplayHandlerResultTest.php`
  - `vendor/bin/phpstan analyse`
  - `vendor/bin/phpunit`
- Acceptance result:
  - Passed
- Remaining phases:
  - Phase 8 — retention coordination
  - Phase 9 — SQL adapters
  - Phase 10 — optional controlled re-injection
  - Phase 11 — hardening and API freeze
- Blockers:
  - None

### Phase 8 — retention coordination

- Implemented explicit filesystem retention coordination through:
  - `CheckpointCompatibilityValidator`
  - `CheckpointAwarePruner`
  - `PrunePolicy`
  - `RetentionPlan`
  - `RetentionResult`
- Retention now validates visible checkpoint compatibility before pruning
- Pruning now removes only a retained ordered prefix and respects a protected tail window
- Size targets are reported truthfully when checkpoints or protected windows prevent further pruning
- Updated retention, checkpoint, storage, public API, stability, and changelog docs
- Files changed:
  - `src/Checkpoint/CheckpointCompatibilityValidator.php`
  - `src/Exceptions/RetentionException.php`
  - `src/Retention/CheckpointAwarePruner.php`
  - `src/Retention/PrunePolicy.php`
  - `src/Retention/RetentionPlan.php`
  - `src/Retention/RetentionResult.php`
  - `tests/Integration/Retention/CheckpointAwarePrunerTest.php`
  - `tests/Unit/Retention/PrunePolicyTest.php`
  - `docs/retention-policy.md`
  - `docs/checkpoint-model.md`
  - `docs/storage-model.md`
  - `docs/public-api.md`
  - `docs/stability-policy.md`
  - `CHANGELOG.md`
- Tests run:
  - `vendor/bin/phpunit tests/Integration/Retention/CheckpointAwarePrunerTest.php tests/Unit/Retention/PrunePolicyTest.php`
  - `vendor/bin/phpstan analyse`
  - `vendor/bin/phpunit`
- Acceptance result:
  - Passed
- Remaining phases:
  - Phase 9 — SQL adapters
  - Phase 10 — optional controlled re-injection
  - Phase 11 — hardening and API freeze
- Blockers:
  - None

### Phase 9 — SQL adapters

- Implemented `SqliteReplayArtifactStore` as a validated SQL backend
- Preserved append-order, cursor resume, read-by-id, and bounded criteria behavior under SQLite
- Extended `ReplayArtifactStore::make()` and `StorageConfig` to support the `sqlite` adapter
- Added cross-adapter contract tests covering filesystem and SQLite parity
- Updated storage docs, public API docs, README, stability policy, and changelog
- Files changed:
  - `src/Adapter/Sqlite/SqliteReplayArtifactStore.php`
  - `src/Config/StorageConfig.php`
  - `src/Storage/ReplayArtifactStore.php`
  - `tests/Integration/Sqlite/SqliteReplayArtifactStoreTest.php`
  - `tests/Contract/ReplayArtifactStoreContractTest.php`
  - `tests/Unit/Config/StorageConfigTest.php`
  - `docs/storage-model.md`
  - `docs/public-api.md`
  - `docs/stability-policy.md`
  - `README.md`
  - `CHANGELOG.md`
- Tests run:
  - `vendor/bin/phpunit tests/Integration/Sqlite/SqliteReplayArtifactStoreTest.php tests/Contract/ReplayArtifactStoreContractTest.php tests/Unit/Config/StorageConfigTest.php`
  - `vendor/bin/phpstan analyse`
  - `vendor/bin/phpunit`
- Acceptance result:
  - Passed
- Remaining phases:
  - Phase 10 — optional controlled re-injection
  - Phase 11 — hardening and API freeze
- Blockers:
  - None

### Phase 10 — optional controlled re-injection

- Implemented guarded optional re-injection through:
  - `ReplayInjectorInterface`
  - `ReplayExecutionCandidate`
  - `InjectionGuard`
  - `ArtifactExecutabilityClassifier`
  - `InjectionResult`
- Re-injection is now disabled by default, requires an explicit allowlist, and requires a caller-supplied injector
- Only `api.dispatch` and `bgapi.dispatch` are intrinsically executable in the current release
- Observational artifacts are rejected clearly and dry-run remains side-effect free
- Updated replay execution docs, public API docs, stability policy, README, and changelog
- Files changed:
  - `src/Contracts/ReplayInjectorInterface.php`
  - `src/Execution/ReplayExecutionCandidate.php`
  - `src/Execution/InjectionGuard.php`
  - `src/Execution/ArtifactExecutabilityClassifier.php`
  - `src/Execution/InjectionResult.php`
  - `src/Config/ExecutionConfig.php`
  - `src/Execution/OfflineReplayExecutor.php`
  - `tests/Fixtures/FakeReplayInjector.php`
  - `tests/Unit/Config/ExecutionConfigTest.php`
  - `tests/Unit/Execution/InjectionGuardTest.php`
  - `tests/Unit/Execution/InjectionResultTest.php`
  - `tests/Integration/OfflineReplayExecutorTest.php`
  - `docs/replay-execution.md`
  - `docs/public-api.md`
  - `docs/stability-policy.md`
  - `README.md`
  - `CHANGELOG.md`
- Tests run:
  - `vendor/bin/phpunit tests/Integration/OfflineReplayExecutorTest.php tests/Unit/Config/ExecutionConfigTest.php tests/Unit/Execution/InjectionGuardTest.php tests/Unit/Execution/InjectionResultTest.php`
  - `vendor/bin/phpstan analyse`
  - `vendor/bin/phpunit`
- Acceptance result:
  - Passed
- Remaining phases:
  - Phase 11 — hardening and API freeze
- Blockers:
  - None

### Phase 11 — hardening and API freeze

- Added large-stream read coverage across filesystem and SQLite adapters
- Added repeated checkpoint save/load stress coverage
- Added offline replay determinism coverage across filesystem and SQLite
- Added explicit SQLite corruption failure coverage for malformed stored JSON columns
- Completed the public API freeze audit in `docs/stability-policy.md`
- Files changed:
  - `src/Adapter/Sqlite/SqliteReplayArtifactStore.php`
  - `tests/Integration/Hardening/LargeStreamAdapterTest.php`
  - `tests/Integration/Checkpoint/CheckpointStressTest.php`
  - `tests/Integration/Execution/OfflineReplayDeterminismTest.php`
  - `tests/Integration/Sqlite/SqliteCorruptionHandlingTest.php`
  - `docs/stability-policy.md`
  - `CHANGELOG.md`
- Tests run:
  - `vendor/bin/phpunit tests/Integration/Hardening/LargeStreamAdapterTest.php tests/Integration/Checkpoint/CheckpointStressTest.php tests/Integration/Execution/OfflineReplayDeterminismTest.php tests/Integration/Sqlite/SqliteCorruptionHandlingTest.php`
  - `vendor/bin/phpstan analyse`
  - `vendor/bin/phpunit`
- Acceptance result:
  - Passed
- Remaining phases:
  - None
- Blockers:
  - None
