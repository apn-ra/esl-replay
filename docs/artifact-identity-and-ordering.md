# Artifact Identity and Ordering

## Identity

### Storage record id

Each stored replay record is assigned a UUID v4 at write time (`ReplayRecordId::generate()`).
Once assigned, the id never changes.

**Uniqueness:** Within a single adapter stream, every record has a unique id.
Cross-stream uniqueness is statistically guaranteed by UUID v4 generation.

**Duplicate writes:** Duplicate artifact writes are allowed. The storage layer does not
deduplicate. Two calls to `write()` for the same `CapturedArtifactEnvelope` produce two
separate `StoredReplayRecord` instances with different ids and append sequences.

**Deduplication:** Not performed. If deduplication is ever required, it must be
implemented explicitly above the storage layer and documented as such.

### Checksum

The checksum field is an integrity marker only.

It can prove that a stored record's canonical artifact fields still match the
checksum computed at write time when a consumer explicitly verifies it. It does
not participate in deduplication semantics.

**Canonical form for checksum computation:**
- Fields included: `artifact_version`, `artifact_name`, `capture_timestamp`, `payload`
- Fields excluded: storage metadata (`id`, `stored_at`, `append_sequence`, `tags`),
  operator identity fields, and other derived/read-optimization fields
- Payload keys are sorted recursively before encoding (for determinism regardless of insertion order)
- Encoded as JSON with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`
- Algorithm: SHA-256

**Verification:** `ArtifactChecksum::verify(StoredReplayRecord)` returns `true` when the
stored checksum matches a freshly computed checksum over the record's artifact fields.
Normal `readFromCursor()` and `readById()` paths do not call this automatically.
Consumers that need integrity checking must call `ArtifactChecksum::verify()`
on returned records and decide how to handle a mismatch.

## Ordering

### Ordering guarantee

Records are stored and returned in **append-sequence order** within a single adapter stream.

- `appendSequence` starts at 1 and increases strictly monotonically within a stream.
- `readFromCursor()` returns records ordered by `appendSequence` ascending.
- `readFromCursor(..., criteria: ...)` preserves that same append-sequence order for matching records.
- Records with `appendSequence <= cursor.lastConsumedSequence` are never returned.

### Ordering boundary

The ordering guarantee applies **within a single adapter stream** (one NDJSON file,
one database partition, etc.).

**Cross-stream global total ordering is not promised** unless explicitly implemented
by a specific adapter and documented as a feature of that adapter.

### Cursor safety

The `ReplayReadCursor` is immutable. Advancing a cursor always requires a strictly
greater sequence number, preventing accidental rewinding.

The `byteOffsetHint` in the cursor is a performance optimisation for file-seek-based
adapters. It is never load-bearing for correctness — an adapter must always apply
the sequence filter even when using the hint.

Bounded read criteria do not change what a cursor means. A saved cursor still
represents the last consumed append sequence within the adapter stream. Applying
criteria narrows which later records are returned; it does not create a second
ordering model or a separate checkpoint concept.

### Restart safety

On process restart:
- The filesystem adapter scans the artifact file to recover the last append sequence.
- New writes continue from the recovered sequence + 1.
- Reads resume correctly from any saved `ReplayReadCursor` position.
- An existing artifact file that cannot be opened during sequence recovery fails
  construction explicitly instead of being treated as an empty stream.
- Only one package filesystem writer may own a storage path at a time, so two
  package writers cannot recover the same tail and emit overlapping append
  sequences concurrently.

Partial writes (e.g. from a crash mid-line) are automatically skipped by the
deserializer, which reads only complete well-formed JSON lines.

## Recovery/evidence ordering rule

The recovery/evidence engine uses the same append-sequence ordering model as
ordinary reads and checkpoints:

- reconstruction windows begin at a `ReplayReadCursor`
- reconstruction consumes stored records in append-sequence order
- comparison and exported evidence bundles preserve that order
- richer runtime metadata does not create a second ordering model

This is why bounded runtime truth in this package is auditable: the evidence
engine reconstructs from the same deterministic ordered stream that ordinary
readers and checkpoint resume use.

## What ordering does NOT guarantee

- The ordering does not imply global wall-clock time ordering if artifacts from
  multiple connection sessions are interleaved in the same stream.
- Capture timestamps within a record reflect the capturing runtime's clock, not
  the storage clock.
- Append sequence does not imply unique session identity — different sessions may
  have artifacts in the same stream with interleaved sequences.
- Bounded filtering is not a general query engine; it narrows the ordered stream
  using a conservative fixed set of exact-match and time-window predicates.
