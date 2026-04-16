# Revised Package Definition and Implementation Plan for `apntalk/esl-replay`

This package definition assumes the architectural boundary already established across the FreeSWITCH ESL package family:

- `apntalk/esl-core` → protocol substrate, frame/event primitives, and shared replay envelope vocabulary
- `apntalk/esl-react` → live async runtime, connection/session supervision, and replay artifact emission
- `apntalk/esl-replay` → durable artifact storage, deterministic reading, checkpointed progress recovery, and offline replay execution
- `apntalk/laravel-freeswitch-esl` → Laravel integration, application-facing wiring, and higher-level operational control plane

The purpose of this revision is to keep `apntalk/esl-replay` sharply bounded and to prevent it from becoming too broad too early.

---

## 1. Purpose

Build `apntalk/esl-replay` as a **durable replay artifact platform for FreeSWITCH ESL runtime output**.

Its core role is to:

- accept replay artifacts emitted by `apntalk/esl-react`
- persist them durably without changing their semantic meaning
- expose deterministic readers over stored artifacts
- support restart-safe replay progress checkpoints
- support offline replay execution from stored artifacts

Optional protocol re-injection may exist later, but it must remain subordinate to the primary purpose of the package.

---

## 2. Product goals

### Primary goals

- Persist replay artifacts durably.
- Preserve captured artifact version and meaning exactly.
- Provide deterministic read and cursor semantics.
- Support restart-safe checkpoint and resume behavior for artifact processing.
- Support offline replay execution from stored artifacts.
- Keep storage concerns separate from runtime concerns.
- Keep execution concerns separate from persistence concerns.

### Secondary goals

- Make artifacts inspectable for debugging, audit, and verification.
- Support multiple storage adapters under one stable contract model.
- Keep artifact schema/version handling explicit and safe.
- Support export of ordered artifact streams for external analysis.

### Non-goals

This package must not:

- become the live ESL session runtime
- own FreeSWITCH socket lifecycle
- perform reconnect supervision
- provide heartbeat/liveness supervision for live runtime
- replace `apntalk/esl-react`
- embed Laravel-specific persistence logic in the package core
- perform business-rule interpretation of telephony flows
- imply live-session restoration after process restart
- auto-execute or auto-reinject stored artifacts by default

---

## 3. Architectural positioning

`apntalk/esl-replay` sits above the live runtime and below framework/application integration.

It owns:

- artifact persistence
- artifact serialization for durable storage
- deterministic artifact reading
- cursor semantics
- checkpointed replay progress
- offline replay planning and execution
- export of stored artifact streams
- optional later re-injection safeguards if introduced

It does **not** own:

- live socket/session lifecycle
- reconnect backoff policy
- live backpressure handling
- subscription/filter mutation management for the live runtime
- business telephony orchestration
- Laravel-specific model/database abstractions
- operational dashboards or control plane UX

---

## 4. Boundary with `apntalk/esl-react`

The contract between the packages must remain narrow.

`apntalk/esl-react` is responsible for:

- observing live runtime behavior
- emitting stable replay artifacts
- preserving runtime meaning at capture time

`apntalk/esl-replay` is responsible for:

- storing those artifacts durably
- reading them deterministically
- managing replay progress over stored artifacts
- executing offline replay scenarios over stored artifacts

`apntalk/esl-replay` must never require `apntalk/esl-react` to become a persistence engine.

---

## 5. Core domain model

This package should explicitly separate three concepts that must not collapse into one type.

### A. Captured artifact envelope

This is the artifact shape emitted by `apntalk/esl-react`.

It is the input contract for this package.

Examples include:

- `api.dispatch`
- `api.reply`
- `bgapi.dispatch`
- `bgapi.ack`
- `bgapi.complete`
- `command.reply`
- `event.raw`
- `subscription.mutate`
- `filter.mutate`

Typical metadata may include:

- `replay-artifact-version`
- `replay-artifact-name`
- `runtime-capture-path`

### B. Stored replay record

This is the persisted record shape owned by `apntalk/esl-replay`.

It is derived from the captured artifact envelope but is not the same thing.

It includes storage-specific metadata required for durable reading and checkpointing, while preserving artifact semantics exactly.

### C. Replay execution candidate

This is a derived execution-facing projection used by offline replay or any later controlled re-injection path.

Not every stored record becomes an execution candidate.

This distinction must be explicit and encoded in the type model.

---

## 6. Storage record model

Each persisted record should include, at minimum:

- storage record id
- artifact version as captured
- artifact name as captured
- capture timestamp
- append sequence within the adapter
- connection generation if present
- session identifier if present
- job UUID if present
- event name if present
- capture path if present
- correlation identifiers if present
- runtime flags if present
- raw or canonicalized persisted payload
- checksum or integrity marker
- optional indexable tags

### Storage rule

The storage layer must preserve the artifact version exactly as captured.

It must not silently upgrade or reinterpret artifact schema during write.

Any later schema migration, reader adaptation, or translation must be explicit and visible.

---

## 7. Artifact identity and ordering

This package must define identity and ordering clearly before expanding adapter support.

### Identity rules

The package must document:

- what uniquely identifies a stored replay record
- whether duplicate artifact writes are allowed
- whether deduplication is supported or intentionally excluded
- whether checksum is integrity-only or also participates in deduplication semantics

### Ordering rules

The package must document:

- whether ordering is global, per file, per partition, or per stream
- what guarantees cursor ordering depends on
- how restart-safe resume preserves ordering assumptions

For the initial release, the safest rule is:

- ordering is defined by append sequence within a single adapter stream
- readers and cursors operate on that ordered append model
- cross-stream global total ordering is not promised unless explicitly implemented later

---

## 8. Public API design

The initial public API should remain deliberately small.

### Stable public contracts for initial releases

- `ReplayArtifactStoreInterface`
- `ReplayArtifactWriterInterface`
- `ReplayArtifactReaderInterface`
- `ReplayCheckpointStoreInterface`
- `OfflineReplayExecutorInterface`

### Public config objects

- `ReplayConfig`
- `StorageConfig`
- `CheckpointConfig`
- `ExecutionConfig`

### Public DTOs

- `StoredReplayRecord`
- `ReplayRecordId`
- `ReplayReadCursor`
- `ReplayCheckpoint`
- `OfflineReplayPlan`
- `OfflineReplayResult`

### Public entry points

- `ReplayArtifactStore::make(ReplayConfig $config): ReplayArtifactStoreInterface`
- `OfflineReplayExecutor::make(ExecutionConfig $config, ReplayArtifactReaderInterface $reader): OfflineReplayExecutorInterface`

### API rule

Only contracts, config objects, DTOs, and a minimal set of entry points should be public and stable in early releases.

These should remain internal or provisional until proven:

- query DSL internals
- report shaping internals
- retention worker internals
- serializer internals
- SQL tuning internals
- any re-injection machinery

---

## 9. Package structure

### Target layout

```text
apntalk/esl-replay/
  composer.json
  README.md
  CHANGELOG.md
  LICENSE
  docs/
    architecture.md
    public-api.md
    artifact-schema.md
    artifact-identity-and-ordering.md
    storage-model.md
    checkpoint-model.md
    replay-execution.md
    retention-policy.md
    stability-policy.md
  examples/
    store-artifacts.php
    read-by-cursor.php
    resume-from-checkpoint.php
    replay-offline.php
    export-stream.php
  src/
    Contracts/
    Config/
    Artifact/
    Serialization/
    Storage/
    Adapter/
    Reader/
    Cursor/
    Checkpoint/
    Execution/
    Export/
    Support/
    Exceptions/
  tests/
    Unit/
    Contract/
    Integration/
    Fixtures/
```

---

## 10. Storage architecture

### Initial storage mode

Start with append-only persistence.

The first adapter should optimize for correctness, inspectability, and restart-safe simplicity.

### Recommended adapter order

1. filesystem NDJSON adapter
2. SQLite adapter
3. PostgreSQL adapter

Laravel-specific persistence helpers, if ever needed, should remain in a higher package rather than inside the core replay package.

### Writer behavior

The writer must:

- accept replay artifacts emitted by `apntalk/esl-react`
- validate supported input shape
- serialize records deterministically
- persist atomically where possible
- return a stable storage id
- fail explicitly and observably
- never mutate artifact meaning

### Reader behavior

The reader must support:

- reading by storage id
- ordered read by cursor
- reading by session when index support exists
- reading by job UUID when index support exists
- reading by artifact name when index support exists
- export of ordered artifact sequences

The initial filesystem implementation may support some lookups through indexed scans rather than promising fully optimized query semantics.

---

## 11. Checkpoint and progress model

This package should define recovery narrowly as **artifact-processing progress recovery**, not live runtime recovery.

### Checkpoint responsibilities

The checkpoint layer must support:

- saving last consumed cursor position
- saving replay execution progress
- loading last known checkpoint after process restart
- validating checkpoint compatibility with retained data

### Critical clarification

A replay checkpoint does **not** restore the original ESL socket session.

It only restores progress over persisted artifact data.

That boundary must be enforced in naming, API design, and documentation.

---

## 12. Offline replay model

Offline replay should be the primary execution mode for this package.

### Primary offline replay use cases

- diagnostics
- test reconstruction
- timeline analysis
- audit reconstruction
- report generation
- deterministic replay against analyzers or handlers

### Offline replay rules

- offline replay must operate only on stored artifacts
- it must not require a live FreeSWITCH socket
- it should support dry-run planning
- it should produce stable execution results given stable input and configuration

---

## 13. Optional controlled re-injection

Controlled protocol re-injection is a secondary and higher-risk capability.

It should not define the package identity.

If introduced later, it must be:

- explicitly configured
- disabled by default
- allowlist-based
- dry-run capable
- clearly traceable
- documented as a separate high-risk operating mode

### Executability distinction

Not all artifacts are executable.

A basic starting rule is:

Potentially replayable under strict policy:

- `api.dispatch`
- `bgapi.dispatch`
- possibly `subscription.mutate`
- possibly `filter.mutate`

Generally observational and not directly re-injected:

- `api.reply`
- `bgapi.ack`
- `bgapi.complete`
- `command.reply`
- `event.raw`

This classification must be explicit and policy-driven rather than implied.

---

## 14. Retention and pruning

Retention is important, but it should not dominate the earliest stable API.

### Retention goals

- control storage growth
- preserve checkpoint compatibility
- avoid corrupting ordered read semantics
- keep pruning explicit and auditable

### Retention rule

Pruning must never invalidate active checkpoints silently.

If stored data required by an active checkpoint is no longer available, that condition must fail clearly and explicitly.

Retention and compaction behavior should be introduced only after append/write/read/checkpoint semantics are proven.

---

## 15. Revised implementation plan

## Phase 1 — Package foundation

### Objective

Create the repository and baseline tooling.

### Deliverables

- `composer.json`
- PSR-4 autoloading
- PHPUnit setup
- PHPStan setup
- CI workflow
- base README
- docs skeleton
- changelog

### Acceptance criteria

- package installs
- CI runs
- static analysis runs
- empty tests pass

---

## Phase 2 — Artifact model and minimal stable contracts

### Objective

Define the artifact envelope boundary, stored record model, and the smallest stable public contract set.

### Deliverables

Contracts for:

- artifact store
- writer
- reader
- checkpoint store
- offline replay executor

Config objects for:

- replay
- storage
- checkpoint
- execution

DTOs for:

- stored replay record
- cursor
- checkpoint
- offline replay plan/result

Documentation for:

- artifact schema
- identity and ordering
- checkpoint model

### Acceptance criteria

- contracts compile
- config objects are immutable
- DTOs are test-covered
- artifact envelope vs stored record vs execution candidate are documented clearly

---

## Phase 3 — Deterministic serialization and append-only writer

### Objective

Implement deterministic persisted-record generation.

### Components

- `ReplayArtifactSerializer`
- `StoredReplayRecordFactory`
- `ReplayArtifactWriter`
- `ArtifactChecksum`

### Features

- stable serialization
- checksum generation
- version-preserving storage payloads
- deterministic record shaping

### Acceptance criteria

- artifacts serialize deterministically
- checksums are reproducible
- unsupported shapes fail explicitly
- identical inputs produce stable persisted output

---

## Phase 4 — Filesystem durability and cursor reads

### Objective

Deliver the first complete working storage adapter.

### Components

- `FilesystemReplayArtifactStore`
- `NdjsonReplayWriter`
- `NdjsonReplayReader`
- `FileReplayCursor`

### Features

- append-only write
- ordered cursor reads
- read by storage id
- export ordered artifact sequences
- restart-safe file reopening

### Acceptance criteria

- artifacts persist durably to filesystem
- cursor iteration is stable
- restart can continue reading correctly
- integration tests prove append/read/resume behavior

---

## Phase 5 — Checkpoints and restart-safe progress recovery

### Objective

Add explicit replay progress persistence and recovery semantics.

### Components

- `ReplayCheckpointStore`
- `ReplayCheckpointService`
- `ExecutionResumeState`

### Features

- persist last consumed cursor
- load checkpoint after restart
- validate checkpoint compatibility
- resume artifact processing safely

### Acceptance criteria

- restart recovery works from saved checkpoints
- missing or invalid checkpoints fail cleanly
- tests prove restart-safe resume over stored artifacts
- documentation makes clear this is not live session recovery

---

## Phase 6 — Reader enrichment and bounded query support

### Objective

Make stored artifacts usable for diagnostics without prematurely freezing an oversized query API.

### Components

- `ReplayReader`
- `ReplayRecordIndex`
- `ReplayReadCriteria` or equivalent bounded criteria object

### Features

- read by time window
- read by artifact name
- read by job UUID
- read by session/generation where available
- ordered streaming export

### Acceptance criteria

- query/read APIs return correct slices
- ordering remains stable
- cursor and criteria behavior stay consistent
- tests prove correctness against fixture streams

---

## Phase 7 — Offline replay execution

### Objective

Turn stored artifacts into deterministic replay scenarios.

### Components

- `OfflineReplayPlanBuilder`
- `OfflineReplayExecutor`
- `ReplayScenario`
- `OfflineReplayReport`

### Features

- artifact-to-scenario planning
- dry-run validation
- offline replay execution
- report generation

### Acceptance criteria

- offline replay can consume stored streams
- dry-run explains intended execution
- execution results are deterministic
- reports are stable and test-covered

---

## Phase 8 — Retention and pruning coordination

### Objective

Control storage growth while preserving checkpoint and replay correctness.

### Components

- `RetentionPlanner`
- `PrunePolicy`
- `CheckpointAwarePruner`

### Features

- retention by age
- retention by size
- protected windows
- checkpoint-aware pruning

### Acceptance criteria

- pruning never breaks active checkpoint assumptions silently
- retention rules are documented
- pruning tests cover edge cases and checkpoint collisions

---

## Phase 9 — Alternate adapters

### Objective

Add more production-friendly persistence backends under proven semantics.

### Components

- `SqliteReplayArtifactStore`
- `PostgresReplayArtifactStore`

### Features

- same contract as filesystem adapter
- indexed reads
- cursor resume
- checkpoint compatibility

### Acceptance criteria

- adapters pass shared contract tests
- query behavior matches documented semantics
- migration/setup docs exist
- adapter-specific optimizations do not change artifact meaning

---

## Phase 10 — Optional controlled re-injection

### Objective

Support explicit, filtered re-injection of replayable artifact types only if justified by real package needs.

### Components

- `ReplayInjector`
- `ArtifactExecutabilityClassifier`
- `InjectionGuard`
- `InjectionResult`

### Features

- replayable artifact allowlist
- explicit execution mode
- rejection of non-executable artifacts
- dry-run and execute modes
- traceable execution results

### Acceptance criteria

- only allowed artifact types are injected
- unsafe artifacts are rejected clearly
- execution is opt-in and guarded
- tests prove policy enforcement

---

## Phase 11 — Hardening and stability freeze

### Objective

Stabilize the package for broader adoption.

### Work

- cross-adapter contract testing
- corruption handling
- partial-write resilience
- large-stream read tests
- checkpoint stress tests
- offline replay determinism tests
- API freeze audit
- schema stability review

### Acceptance criteria

- adapters behave consistently
- restart-safe processing is robust
- execution safety is documented and enforced
- public API is stable enough for release

---

## 16. Revised milestone release plan

## Milestone 0.1.0

Foundation and minimal contracts

Includes:

- package foundation
- artifact/store/reader/checkpoint contracts
- config and DTOs
- documentation skeleton

## Milestone 0.2.0

Deterministic serialization and filesystem durability

Includes:

- serializer
- checksum
- filesystem append-only adapter
- basic cursor reads

## Milestone 0.3.0

Checkpointed progress recovery

Includes:

- checkpoint store
- restart-safe cursor resume
- checkpoint validation

## Milestone 0.4.0

Bounded reader enrichment

Includes:

- read criteria
- session/job/artifact-name filtering
- export support

## Milestone 0.5.0

Offline replay

Includes:

- replay planning
- dry-run mode
- offline execution reports

## Milestone 0.6.0

Retention coordination

Includes:

- retention rules
- checkpoint-aware pruning
- storage lifecycle controls

## Milestone 0.7.0

SQL adapters

Includes:

- SQLite adapter
- PostgreSQL adapter
- cross-adapter contract tests

## Milestone 0.8.0

Optional controlled re-injection

Includes:

- guarded injector
- executability classification
- dry-run and execute modes

## Milestone 0.9.0

Hardening

Includes:

- corruption resilience
- large-stream tests
- restart stress tests
- API freeze audit

## Milestone 1.0.0

Stable replay platform

Criteria:

- artifact schema/version handling is stable
- storage contracts are stable
- checkpoint/resume behavior is restart-safe
- offline replay is stable
- adapter behavior is contract-consistent
- optional re-injection, if shipped, is explicit and well-guarded

---

## 17. Testing plan

### Unit tests

Cover:

- config guards
- serializer determinism
- checksum behavior
- cursor normalization
- checkpoint compatibility rules
- offline replay planning
- retention policy logic

### Contract tests

Cover:

- `ReplayArtifactStoreInterface`
- `ReplayArtifactReaderInterface`
- `ReplayCheckpointStoreInterface`
- `OfflineReplayExecutorInterface`
- adapter consistency

### Integration tests

Cover:

- append and read
- restart-safe cursor recovery
- checkpoint load/save
- bounded filtering
- export flows
- offline replay execution
- retention/pruning behavior

### Fixture tests

Cover:

- real artifact samples from `apntalk/esl-react`
- versioned artifact schema compatibility
- malformed or partial stored records
- checksum/integrity edge cases

---

## 18. Documentation plan

Create:

- `architecture.md`
- `public-api.md`
- `artifact-schema.md`
- `artifact-identity-and-ordering.md`
- `storage-model.md`
- `checkpoint-model.md`
- `replay-execution.md`
- `retention-policy.md`
- `stability-policy.md`

README should explain:

- what `apntalk/esl-replay` is
- how it relates to `esl-core` and `esl-react`
- what belongs in this package
- what does not belong in this package
- quick start for storing artifacts
- quick start for reading artifacts
- quick start for checkpoint/resume
- quick start for offline replay
- safety warning for any later re-injection mode

---

## 19. Risks and controls

### Risk 1 — Package expands into an unbounded data platform

Replay storage can drift into a generalized persistence product.

#### Control

Keep the first releases centered on append-only durability, deterministic reads, and restart-safe checkpoints.

---

### Risk 2 — Recovery is confused with live runtime continuity

Consumers may misread restart recovery as live socket/session restoration.

#### Control

Use strict naming and documentation that define recovery only as persisted-artifact progress recovery.

---

### Risk 3 — Storage and execution concerns become entangled

Execution needs can distort durable storage design.

#### Control

Separate artifact persistence, checkpointed progress, and replay execution into distinct internal layers.

---

### Risk 4 — Schema drift between packages

If `esl-react` and `esl-replay` diverge in artifact vocabulary, storage becomes fragile.

#### Control

Keep replay artifact primitives and canonical vocabulary in `esl-core` where appropriate.

---

### Risk 5 — SQL adapters diverge from filesystem semantics

Backends may silently change ordering or checkpoint behavior.

#### Control

Use shared contract tests and explicit ordering/identity documentation across adapters.

---

### Risk 6 — Re-injection becomes unsafe or misleading

Stored artifacts may be treated as executable when they are not.

#### Control

Keep re-injection optional, off by default, allowlist-based, dry-run capable, and separately documented.

---

## 20. Success criteria

The package is successful when it can:

- durably store replay artifacts emitted by `esl-react`
- preserve artifact version and meaning exactly
- read and stream artifacts deterministically
- recover replay-processing progress safely after restart
- execute offline replay scenarios deterministically
- keep adapter behavior contract-consistent
- remain clearly separated from the live runtime layer

If optional re-injection is later added, success also requires that it be explicit, guarded, and clearly subordinate to the core replay platform purpose.

---

## 21. Strong implementation recommendation

Build in this order:

1. artifact model and minimal stable contracts
2. deterministic serialization
3. filesystem durability
4. checkpointed progress recovery
5. bounded reader/query enrichment
6. offline replay execution
7. retention coordination
8. SQL adapters
9. optional controlled re-injection
10. hardening and API freeze

This order gives the package a useful and disciplined core early:

- durable storage
- deterministic reads
- restart-safe progress
- offline replay

That is enough to make `apntalk/esl-replay` valuable before taking on higher-risk execution paths.
