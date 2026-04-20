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

### Saving with operational identity anchors

```php
$repository = new ReplayCheckpointRepository($store);

$repository->save(
    new ReplayCheckpointReference(
        key: 'worker-a',
        replaySessionId: 'replay-session-001',
        jobUuid: 'job-123',
        pbxNodeSlug: 'pbx-a',
        workerSessionId: 'worker-a',
    ),
    $cursor,
);
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

### Bounded operational checkpoint lookup

```php
$matches = $repository->find(new ReplayCheckpointCriteria(
    replaySessionId: 'replay-session-001',
    workerSessionId: 'worker-a',
));
```

Checkpoint lookup remains intentionally narrow: exact-match filters only over
`replay_session_id`, `job_uuid`, `pbx_node_slug`, and `worker_session_id`.
It is not a general checkpoint search API.

## Filesystem checkpoint persistence

Checkpoints are stored as individual JSON files:

```
/var/checkpoints/{sanitized-key-prefix}-{sha256-original-key}.checkpoint.json
```

Writes are atomic: the JSON is written to a `.tmp` staging file, then renamed
over the target path (POSIX rename is atomic).

## Checkpoint compatibility

A checkpoint is compatible if the stored records it points to still exist in the
artifact stream. If a checkpoint points beyond the end of the artifact stream
(e.g. the file was rotated or pruned), reads simply return an empty result — no
records will match `appendSequence > lastConsumedSequence` since none exist.

Retention pruning is now coordinated through `CheckpointCompatibilityValidator`
and `CheckpointAwarePruner`. Before pruning, active checkpoints are validated
against the currently retained stream. If the stream already starts after the
next sequence a checkpoint would need, retention fails explicitly via
`RetentionException`.

## Key sanitisation

Checkpoint keys are sanitised before use as filenames. Only `[a-zA-Z0-9\-_.]`
are allowed. Other characters are collapsed to `_`. Leading/trailing dots are
stripped. The persisted filename also includes a SHA-256 hash of the original
key, so distinct keys such as `my/key`, `my key`, and `my_key` do not collide
even though they share the same sanitised prefix.
