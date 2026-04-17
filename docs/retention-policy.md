# Retention Policy

Retention and pruning are implemented conservatively for the filesystem adapter
through `CheckpointAwarePruner`.

## Goal

Retention controls storage growth while preserving checkpoint and replay correctness.

## Retention requirements

Retention must:

- Never invalidate active checkpoints silently
- Preserve ordered read semantics across the remaining artifact stream
- Fail explicitly and observably when retained data is required by an active checkpoint
- Be configured explicitly — no implicit or automatic pruning

## Checkpoint compatibility rule

If a checkpoint is already behind the retained stream start, the validator raises
`RetentionException` before pruning continues. Pruning also refuses to remove
records beyond the oldest active checkpoint cursor, so active checkpoints are not
silently invalidated by this package.

## Retention strategies

| Strategy | Description |
|---|---|
| Retention by age | Prune an eligible ordered prefix whose captured timestamp is older than a configured age |
| Retention by size | Prune an eligible ordered prefix until the retained stream is at or below a byte target, when possible |
| Protected window | Preserve the newest N records regardless of age or size pressure |

## Current filesystem behavior

- Retention planning and pruning are explicit API calls only.
- Pruning removes only an ordered prefix of the adapter stream.
- The newest protected window is never pruned.
- Active checkpoints cap the prune boundary at the oldest checkpoint cursor.
- Malformed retained input causes retention planning/pruning to fail explicitly.
- Size targets are best-effort under safety constraints; when checkpoints or the
  protected window prevent further pruning, the plan reports that the size target
  could not be fully satisfied.
- Pruning rewrites the filesystem NDJSON stream via temp file plus atomic rename.
- `CheckpointAwarePruner` can also resolve active checkpoints from a bounded
  checkpoint query before planning or pruning, so operators do not need to
  enumerate checkpoint arrays manually.

## Scope boundary

Retention does not change append-order semantics, checkpoint meaning, or live
runtime recovery semantics. It coordinates durable stored-artifact pruning only.
