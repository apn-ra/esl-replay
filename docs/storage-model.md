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

`readById()` performs a full file scan. For high-volume by-id lookups, a database
adapter with an indexed `id` column is more appropriate.

### Restart-safe sequence recovery

On construction, `FilesystemReplayArtifactStore` scans the artifact file and records
the highest `appendSequence` found. New writes continue from that sequence + 1.

Partial writes (incomplete JSON lines from a prior crash) are silently skipped by
the deserializer. They do not corrupt subsequent reads.

### Concurrency model

The filesystem adapter is designed for **single-writer, multiple-reader** use within
a single process.

For concurrent multi-process writes, use a database adapter (SQLite or PostgreSQL),
which provides database-level concurrency control.

### File layout

```
/var/replay/
  artifacts.ndjson        ← one StoredReplayRecord per line
```

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

## Future adapters

Future adapters (SQLite, PostgreSQL) will implement the same
`ReplayArtifactStoreInterface` contract. They will pass the same contract test
suite to prove behavioral equivalence. Cross-adapter ordering and identity rules
are documented in `docs/artifact-identity-and-ordering.md`.
