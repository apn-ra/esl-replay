# Architecture

## Package family boundary

`apntalk/esl-replay` is one of four packages in the FreeSWITCH ESL family:

| Package | Role |
|---|---|
| `apntalk/esl-core` | Protocol substrate, frame/event primitives, shared replay envelope vocabulary |
| `apntalk/esl-react` | Live async runtime, connection/session supervision, replay artifact emission |
| `apntalk/esl-replay` | Durable artifact persistence, deterministic reading, checkpointed progress recovery, offline replay, bounded recovery/evidence reconstruction |
| `apntalk/laravel-freeswitch-esl` | Laravel integration, application-facing wiring, operational control plane |

`apntalk/esl-replay` sits above the live runtime layer and below application/framework integration.

## What this package owns

- Artifact persistence (accepting artifacts emitted by `esl-react`)
- Deterministic serialization for durable storage
- Deterministic artifact reading with cursor semantics
- Checkpointed replay progress (over stored artifacts)
- Offline replay planning and execution
- Export of stored artifact streams
- Recovery/evidence manifests and deterministic reports reconstructed from stored artifacts

## What this package does NOT own

- Live socket/session lifecycle
- Reconnect backoff policy
- Live backpressure handling
- Subscription/filter mutation management for the live runtime
- Business telephony orchestration
- Laravel-specific model or database abstractions
- Operational dashboards or control plane UX
- Automatic live-session restoration after process restart
- Auto-execution or auto-reinjection by default

## Three core concepts

This package strictly separates three concepts that must not collapse into one type:

### 1. Captured artifact envelope (`CapturedArtifactEnvelope`)

The input contract for this package. Emitted by `apntalk/esl-react`.

Examples: `api.dispatch`, `api.reply`, `bgapi.dispatch`, `bgapi.ack`, `bgapi.complete`,
`command.reply`, `event.raw`, `subscription.mutate`, `filter.mutate`.

This package stores these without altering their semantic meaning.

### 2. Stored replay record (`StoredReplayRecord`)

The persisted durable record owned by this package. Derived from a
`CapturedArtifactEnvelope` at write time but is a distinct type.

Adds storage-layer metadata (id, storedAt, appendSequence, checksum) while preserving
artifact version and payload exactly as captured.

### 3. Replay execution candidate

An execution-facing projection used by offline replay or any later re-injection path.
Not every stored record becomes an execution candidate.

This distinction is explicit in naming, types, and documentation.

## Internal layers

```
ReplayArtifactStore::make()        ← public stable entry point
  └── FilesystemReplayArtifactStore
        ├── NdjsonReplayWriter      ← append-only write
        └── NdjsonReplayReader      ← cursor-based read

OfflineReplayExecutor::make()      ← public stable entry point
  └── OfflineReplayExecutor        ← plan() + execute()
        ├── ReplayHandlerRegistry  ← exact artifact-name handler dispatch
        └── guarded re-injection   ← explicit allowlist + caller-supplied injector

FilesystemCheckpointStore          ← checkpoint persistence
  └── used via ReplayCheckpointService (higher-level API)
  └── used via ExecutionResumeState (startup resolution)

CheckpointAwarePruner              ← explicit filesystem retention coordination
  └── uses CheckpointCompatibilityValidator before pruning
```

## Storage adapter order

The implementation plan targeted adapters in this order:

1. **Filesystem NDJSON** — append-only, inspectable, restart-safe
2. SQLite
3. PostgreSQL (future)

Filesystem NDJSON and SQLite are implemented in the current release. Both
implement the same `ReplayArtifactStoreInterface` contract and are covered by
shared contract tests for append-order, cursor, restart, and bounded-filter
semantics.

PostgreSQL remains future work and is not part of the current stable surface.

## Recovery semantics

**Recovery in this package means artifact-processing progress recovery.**

It does NOT mean:
- restoring a live FreeSWITCH socket
- restoring the original ESL session
- resuming live runtime continuity
- recreating live runtime supervision state

A checkpoint saves and restores the cursor position over stored artifact data only.

## Recovery/evidence semantics

This package may now reconstruct **bounded runtime truth** from stored artifacts
for audit and recovery evidence. That means:

- consume richer runtime-truth metadata already present in stored artifact
  payloads, runtime flags, correlation ids, and checkpoint metadata
- reconstruct deterministic recovery/evidence projections over append-ordered
  stored records
- compare reconstructed truth to scenario expectations
- export deterministic JSON bundles and reports

This still does **not** mean:

- restoring a live FreeSWITCH socket
- restoring a live ESL session
- recreating live runtime supervision state
- claiming live continuity after restart
