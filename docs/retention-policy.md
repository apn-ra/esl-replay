# Retention Policy

> **Status: Not yet implemented.**
> Retention and pruning are planned for Phase 8 of the implementation roadmap.
> This document describes the intended design and constraints.

## Goal

Retention controls storage growth while preserving checkpoint and replay correctness.

## Retention requirements

When implemented, retention must:

- Never invalidate active checkpoints silently
- Preserve ordered read semantics across the remaining artifact stream
- Fail explicitly and observably when retained data is required by an active checkpoint
- Be configured explicitly — no implicit or automatic pruning

## Checkpoint compatibility rule

If a checkpoint's `lastConsumedSequence` refers to a record that has been pruned,
the reader will return an empty result (no records with higher sequence exist in
the pruned stream). Processing appears to complete immediately with no error —
which would silently skip data.

To prevent this, the retention layer must:
1. Validate checkpoint compatibility before pruning
2. Fail explicitly if pruning would invalidate an active checkpoint
3. Provide an audit trail of what was pruned and when

## Retention strategies (planned)

| Strategy | Description |
|---|---|
| Retention by age | Prune records older than N days |
| Retention by size | Prune oldest records when stream exceeds N bytes |
| Protected window | Preserve records within the last N sequences regardless of age |

## Implementation order

Retention must not be introduced before:

1. Append/write/read/cursor semantics are stable
2. Checkpoint load/save/resume is stable
3. Cross-adapter ordering guarantees are documented

Retention is Phase 8 in the implementation plan.
