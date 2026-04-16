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

It proves that a stored record's artifact fields have not been corrupted since write time.
It does not participate in deduplication semantics.

**Canonical form for checksum computation:**
- Fields included: `artifact_version`, `artifact_name`, `capture_timestamp`, `payload`
- Payload keys are sorted recursively before encoding (for determinism regardless of insertion order)
- Encoded as JSON with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`
- Algorithm: SHA-256

**Verification:** `ArtifactChecksum::verify(StoredReplayRecord)` returns `true` when the
stored checksum matches a freshly computed checksum over the record's artifact fields.
Readers may call this at read time to detect storage corruption.

## Ordering

### Ordering guarantee

Records are stored and returned in **append-sequence order** within a single adapter stream.

- `appendSequence` starts at 1 and increases strictly monotonically within a stream.
- `readFromCursor()` returns records ordered by `appendSequence` ascending.
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

### Restart safety

On process restart:
- The filesystem adapter scans the artifact file to recover the last append sequence.
- New writes continue from the recovered sequence + 1.
- Reads resume correctly from any saved `ReplayReadCursor` position.

Partial writes (e.g. from a crash mid-line) are automatically skipped by the
deserializer, which reads only complete well-formed JSON lines.

## What ordering does NOT guarantee

- The ordering does not imply global wall-clock time ordering if artifacts from
  multiple connection sessions are interleaved in the same stream.
- Capture timestamps within a record reflect the capturing runtime's clock, not
  the storage clock.
- Append sequence does not imply unique session identity — different sessions may
  have artifacts in the same stream with interleaved sequences.
