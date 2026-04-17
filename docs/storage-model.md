# Storage Model

## Filesystem NDJSON adapter

The initial storage adapter writes artifacts to an append-only NDJSON file:

```
{storagePath}/artifacts.ndjson
```

Each line is a complete serialized `StoredReplayRecord` (one JSON object per line).

### Write behavior

1. The writer acquires an exclusive file lock (`LOCK_EX`).
2. The artifact is serialized to a single JSON line.
3. The line is appended with a trailing `\n`.
4. The file is flushed before the lock is released.
5. The storage record id is returned to the caller.

On failure, `ArtifactPersistenceException` is thrown. Artifacts are never silently dropped.

### Read behavior

Reads are sequential scans. The reader opens the file, seeks to the byte-offset hint
if provided (as a performance optimization), and scans forward returning records whose
`appendSequence` is strictly greater than the cursor's `lastConsumedSequence`.

When `ReplayReadCriteria` is provided, the reader applies conservative bounded
filtering during the same ordered scan. Supported filters are:
- inclusive capture-time window (`capturedFrom` / `capturedUntil`)
- exact `artifactName`
- exact `jobUuid`
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

Partial writes (incomplete JSON lines from a prior crash) are silently skipped by
the deserializer. They do not corrupt subsequent reads.

### Concurrency model

The filesystem adapter is designed for **single-writer, multiple-reader** use within
a single process.

For concurrent multi-process writes, use a database adapter. The current release
includes a SQLite adapter with database-level concurrency control that preserves
the same replay contract semantics as the filesystem adapter.

### File layout

```
/var/replay/
  artifacts.ndjson        ← one StoredReplayRecord per line
```

### Filesystem retention behavior

`CheckpointAwarePruner` coordinates filesystem retention by rewriting
`artifacts.ndjson` through a temp file and atomic rename. Pruning removes only
an ordered prefix of valid stored records and preserves append-sequence values on
retained records.

Unlike ordinary read paths, retention/rewrite planning is stricter: malformed
retained input causes pruning to fail explicitly rather than silently skipping
bad lines and rewriting around them.

### Checkpoint file layout

```
/var/checkpoints/
  {key}.checkpoint.json   ← one JSON object per checkpoint key
  {key}.checkpoint.json.tmp ← atomic write staging file (transient)
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
- `replaySessionId` (derived from `correlation_ids.replay_session_id`, with
  fallback to `runtime_flags.replay_session_id` when present)
- `pbxNodeSlug` (derived from `runtime_flags.pbx_node_slug`)
- `workerSessionId` (derived from `runtime_flags.worker_session_id`)

For SQLite, these operator fields are also persisted into additive derived
columns so bounded inspection does not require downstream scan-and-filter logic.
`database` is a compatibility alias for this same SQLite-backed adapter.

PostgreSQL remains future work. Cross-adapter ordering and identity rules are
documented in `docs/artifact-identity-and-ordering.md`.
