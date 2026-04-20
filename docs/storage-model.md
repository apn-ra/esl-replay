# Storage Model

## Filesystem NDJSON adapter

The initial storage adapter writes artifacts to an append-only NDJSON file:

```
{storagePath}/artifacts.ndjson
```

Each line is a complete serialized `StoredReplayRecord` (one JSON object per line).

### Write behavior

1. The store/writer acquires a long-lived exclusive writer ownership lock
   (`artifacts.ndjson.writer.lock`) during write-capable initialization.
2. Each write acquires a short-lived exclusive sibling coordination lock
   (`artifacts.ndjson.lock`) before opening the artifact file.
3. The artifact is serialized to a single JSON line.
4. The line is appended with a trailing `\n`.
5. The file is flushed before the coordination lock is released.
6. The storage record id is returned to the caller.

On failure, `ArtifactPersistenceException` is thrown. Artifacts are never silently dropped.
The ownership lock prevents two package filesystem writers from owning the same
append-sequence stream at the same time. The coordination lock is shared with
filesystem retention rewriting, so package writers either finish before pruning
starts or open the replacement `artifacts.ndjson` after pruning completes.

### Read behavior

Reads are sequential scans. The reader opens the file, seeks to the byte-offset hint
if provided (as a performance optimization), and scans forward returning records whose
`appendSequence` is strictly greater than the cursor's `lastConsumedSequence`.

When `ReplayReadCriteria` is provided, the reader applies conservative bounded
filtering during the same ordered scan. Supported filters are:
- inclusive capture-time window (`capturedFrom` / `capturedUntil`)
- exact `artifactName`
- exact `jobUuid`
- exact `replaySessionId`
- exact `pbxNodeSlug`
- exact `workerSessionId`
- exact `sessionId`
- exact `connectionGeneration`

Filtering does not change record meaning, cursor meaning, or ordering semantics.
The reader still returns matching records in append-sequence order within the
single adapter stream.

`readById()` performs a full file scan. For high-volume by-id lookups, a database
adapter with an indexed `id` column is more appropriate.

On the filesystem path, ordinary reads intentionally skip malformed persisted
lines. This is a conservative partial-write tolerance strategy for append-only
storage; it does not reinterpret malformed data as valid records.

### Restart-safe sequence recovery

On construction, `FilesystemReplayArtifactStore` scans the artifact file and records
the highest `appendSequence` found. New writes continue from that sequence + 1.

If `artifacts.ndjson` does not exist yet, recovery starts at sequence `0`. If
the file exists but cannot be opened for recovery, construction fails explicitly
with `ArtifactPersistenceException` rather than treating the existing stream as
empty and risking duplicate append sequences.

Partial writes (incomplete JSON lines from a prior crash) are silently skipped by
the deserializer. They do not corrupt subsequent reads.

### Concurrency model

The filesystem adapter enforces **single package writer ownership** for a storage
path. A second package filesystem writer/store pointed at the same path fails
closed with `ArtifactPersistenceException` while the first owner is active.
Multiple readers may inspect the file while one package writer owns it.

This guarantee applies to writers that use this package. External processes
that bypass `FilesystemReplayArtifactStore` must not write `artifacts.ndjson`.
For broader concurrent write scenarios, use a database adapter. The current
release includes a SQLite adapter with database-level concurrency control that
preserves the same replay contract semantics as the filesystem adapter.

### File layout

```
/var/replay/
  artifacts.ndjson        ← one StoredReplayRecord per line
  artifacts.ndjson.writer.lock ← long-lived package writer ownership lock
  artifacts.ndjson.lock   ← short-lived append/prune coordination lock
```

### Filesystem retention behavior

`CheckpointAwarePruner` coordinates filesystem retention by rewriting
`artifacts.ndjson` through a temp file and atomic rename. Pruning removes only
an ordered prefix of valid stored records and preserves append-sequence values on
retained records. Before rewrite planning starts, pruning acquires the same
`artifacts.ndjson.lock` sibling lock used by the package filesystem writer. If
the lock is already held, pruning fails closed with `RetentionException` rather
than rewriting while a writer may still append to the previous inode.

Unlike ordinary read paths, retention/rewrite planning is stricter: malformed
retained input causes pruning to fail explicitly rather than silently skipping
bad lines and rewriting around them.

### Checkpoint file layout

```
/var/checkpoints/
  {sanitized-key-prefix}-{sha256-original-key}.checkpoint.json   ← one JSON object per checkpoint key
  {sanitized-key-prefix}-{sha256-original-key}.checkpoint.json.tmp ← atomic write staging file (transient)
```

## Schema versioning

Every serialized line includes `"schema_version": 1`.

When `schema_version` is unknown, `SerializationException` is thrown. The caller
must handle schema evolution explicitly — silent schema upgrades are forbidden.

## Alternate adapters

The current release includes:
- filesystem NDJSON
- SQLite

SQLite persists the same stored record fields, orders reads by `append_sequence`,
and supports the same bounded reader criteria as the filesystem adapter.
Current exact-match operator fields include:
- `jobUuid`
- `replaySessionId` (derived from
  `correlation_ids[OperatorIdentityKeys::REPLAY_SESSION_ID]`, with fallback to
  `runtime_flags[OperatorIdentityKeys::REPLAY_SESSION_ID]` when present)
- `pbxNodeSlug` (derived from `runtime_flags[OperatorIdentityKeys::PBX_NODE_SLUG]`)
- `workerSessionId` (derived from `runtime_flags[OperatorIdentityKeys::WORKER_SESSION_ID]`)

For SQLite, these operator fields are also persisted into additive derived
columns so bounded inspection does not require downstream scan-and-filter logic.
`database` is a compatibility alias for this same SQLite-backed adapter.

PostgreSQL remains future work. Cross-adapter ordering and identity rules are
documented in `docs/artifact-identity-and-ordering.md`.
