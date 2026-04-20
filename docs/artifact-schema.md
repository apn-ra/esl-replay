# Artifact Schema

## Captured artifact envelope

The input contract for this package. `CapturedArtifactEnvelope` is an interface
implemented by artifacts emitted by `apntalk/esl-react`.

Known artifact names:

| Name | Description |
|---|---|
| `api.dispatch` | A synchronous API command dispatched to FreeSWITCH |
| `api.reply` | The reply to an `api.dispatch` |
| `bgapi.dispatch` | A background API command dispatched to FreeSWITCH |
| `bgapi.ack` | Acknowledgement that FreeSWITCH accepted the bgapi command |
| `bgapi.complete` | Completion of a background API command |
| `command.reply` | Reply to an ESL protocol command |
| `event.raw` | A raw FreeSWITCH event |
| `subscription.mutate` | A mutation of the event subscription set |
| `filter.mutate` | A mutation of the event filter set |

This package does not validate artifact names against a fixed allowlist.
New artifact types introduced by `esl-react` are stored transparently.

## Stored record schema

Version: **1** (schema_version field in serialized NDJSON).

Each persisted NDJSON line carries these fields:

| Field | Type | Description |
|---|---|---|
| `schema_version` | `int` | Serialized schema version. Currently 1. |
| `id` | `string` (UUID v4) | Storage record id. Unique, assigned at write time. |
| `artifact_version` | `string` | Replay artifact schema version as captured. Never upgraded silently. |
| `artifact_name` | `string` | Artifact name as captured (e.g. `api.dispatch`). |
| `capture_timestamp` | `string` (RFC3339_EXTENDED, UTC) | Timestamp from the capturing runtime. |
| `stored_at` | `string` (RFC3339_EXTENDED, UTC) | Timestamp when this record was persisted. |
| `append_sequence` | `int` | Monotonically increasing position within the adapter stream. Starts at 1. |
| `connection_generation` | `string\|null` | Connection generation counter from esl-react, if present. |
| `session_id` | `string\|null` | ESL session identifier at capture time, if present. |
| `job_uuid` | `string\|null` | Background job UUID, if present. |
| `event_name` | `string\|null` | FreeSWITCH event name, if present. |
| `capture_path` | `string\|null` | Runtime capture path, if recorded. |
| `correlation_ids` | `object` | Correlation identifiers linking related artifacts. |
| `runtime_flags` | `object` | Runtime flags recorded at capture time. |
| `payload` | `object` | Raw artifact payload as captured. Never reinterpreted. |
| `checksum` | `string` (SHA-256 hex) | Integrity marker. See `docs/artifact-identity-and-ordering.md`. |
| `tags` | `object` | Optional indexable tags added at storage time. |

## Operator identity keys

`OperatorIdentityKeys` publishes the stable cross-package key names expected
from upstream producers such as `apntalk/esl-react`:

| Constant | Key | Expected location |
|---|---|---|
| `OperatorIdentityKeys::REPLAY_SESSION_ID` | `replay_session_id` | Prefer `correlation_ids`; `runtime_flags` is accepted as a fallback for derived inspection |
| `OperatorIdentityKeys::PBX_NODE_SLUG` | `pbx_node_slug` | `runtime_flags` |
| `OperatorIdentityKeys::WORKER_SESSION_ID` | `worker_session_id` | `runtime_flags` |

These keys are used for bounded read criteria, SQLite derived inspection
columns, and checkpoint metadata created through `ReplayCheckpointReference`.
They are additive identity metadata and are not included in checksum
computation.

## Richer runtime-truth surfaces within schema version 1

`schema_version: 1` remains stable.

Newer `esl-react` releases may emit richer runtime-truth surfaces such as:

- prepared recovery context
- runtime recovery snapshot
- runtime operation snapshot
- runtime terminal-publication snapshot
- runtime lifecycle-semantic snapshot
- enriched replay metadata

`apntalk/esl-replay` consumes these through the existing object fields it
already preserves exactly:

- `payload`
- `runtime_flags`
- `correlation_ids`
- checkpoint `metadata`

No schema bump is required for these additive fields because:

- the storage layer already preserves arbitrary object keys in these fields
- append ordering does not change
- checksum semantics do not change
- readers that do not inspect the richer metadata remain compatible

The recovery/evidence engine fails closed when these richer fields are absent or
internally contradictory, rather than inventing missing runtime truth.

## Schema version rule

When a reader encounters a `schema_version` other than the supported version,
it must throw `SerializationException`. It must never silently ignore an
unknown version.

Future schema changes must bump `schema_version` and provide explicit migration
or reader-adaptation code.

## Payload preservation rule

The `payload` field is stored verbatim from `CapturedArtifactEnvelope::getPayload()`.

The storage layer must not:
- silently upgrade schema during write
- reinterpret artifact meaning during persistence
- mutate captured semantics for storage convenience

Any migration, translation, or reader adaptation must be explicit and visible.
