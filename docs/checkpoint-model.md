# Checkpoint Model

## What a checkpoint is

A checkpoint saves the cursor position of the last successfully consumed artifact,
so that artifact processing can resume from that position after a process restart.

## What a checkpoint is NOT

**A replay checkpoint is not live-session recovery.**

A checkpoint restores **artifact-processing progress** only. It does NOT restore:

- a live FreeSWITCH socket
- a live ESL session managed by `apntalk/esl-react`
- runtime continuity
- reconnect state
- subscription or filter configuration for the live runtime

This distinction is fundamental to the package boundary. Any documentation,
naming, or API design that blurs this line must be corrected.

## Checkpoint structure

```php
final readonly class ReplayCheckpoint
{
    public string $key;                 // identifies the processing context
    public ReplayReadCursor $cursor;    // the cursor to resume from
    public \DateTimeImmutable $savedAt; // UTC timestamp of when this was saved
    public array $metadata;             // optional caller-supplied metadata
}
```

The `cursor->lastConsumedSequence` is the append sequence of the last record
that was successfully processed before the checkpoint was saved.

On resume, the reader returns records with `appendSequence > lastConsumedSequence`.
The record at `lastConsumedSequence` is NOT reprocessed.

## Checkpoint lifecycle

### Saving a checkpoint

```php
$service = new ReplayCheckpointService($store, 'my-processor');
$service->save($cursor);  // saves current position
```

### Resolving startup position

```php
$state = ExecutionResumeState::resolve($store, 'my-processor');

if ($state->isResuming) {
    // $state->cursor is the saved position — resume from here
} else {
    // $state->cursor is ReplayReadCursor::start() — fresh run
}
```

### Clearing a checkpoint

```php
$service->clear();  // removes the checkpoint — next run starts fresh
```

## Filesystem checkpoint persistence

Checkpoints are stored as individual JSON files:

```
/var/checkpoints/{key}.checkpoint.json
```

Writes are atomic: the JSON is written to a `.tmp` staging file, then renamed
over the target path (POSIX rename is atomic).

## Checkpoint compatibility

A checkpoint is compatible if the stored records it points to still exist in the
artifact stream. If a checkpoint points beyond the end of the artifact stream
(e.g. the file was rotated or pruned), reads simply return an empty result — no
records will match `appendSequence > lastConsumedSequence` since none exist.

When retention pruning is implemented (Phase 8), pruning must never invalidate
active checkpoints silently. That condition must fail explicitly and observably.

## Key sanitisation

Checkpoint keys are sanitised before use as filenames. Only `[a-zA-Z0-9\-_.]`
are allowed. Other characters are collapsed to `_`. Leading/trailing dots are
stripped. Keys that reduce to empty strings are hashed with md5.
