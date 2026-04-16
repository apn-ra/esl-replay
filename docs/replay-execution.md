# Replay Execution

## Offline replay

Offline replay is the primary execution mode of this package.

Offline replay:
- operates only on stored artifacts
- does **not** require a live FreeSWITCH socket
- does **not** connect to FreeSWITCH
- does **not** send commands to FreeSWITCH unless re-injection is explicitly configured

Primary use cases:
- diagnostics
- test reconstruction
- timeline analysis
- audit reconstruction
- report generation
- deterministic replay against analyzers or handlers

## Plan / execute pattern

Offline replay uses a plan-then-execute pattern:

```php
$executor = OfflineReplayExecutor::make($config, $reader);

// 1. Build a plan — inspect before executing
$plan = $executor->plan($cursor);

if ($plan->isEmpty()) {
    // No new records to process
    return;
}

echo "Records to replay: {$plan->recordCount}";

// 2. Execute the plan
$result = $executor->execute($plan);

if ($result->success) {
    echo "Processed: {$result->processedCount}, Skipped: {$result->skippedCount}";
}
```

## Dry-run mode (default)

When `ExecutionConfig::dryRun` is `true` (the default), `execute()` marks all
records as `dry_run_skip` and produces no side effects. `processedCount` will
be 0 and `skippedCount` will equal the number of records in the plan.

Dry-run is the safe default. It allows inspecting what would be executed before
committing.

## Live/observational mode

When `ExecutionConfig::dryRun` is `false`, `execute()` processes records and
records outcomes. In this release, records are marked `observed` — handler
dispatch is reserved for a future implementation phase.

## Batch limit

`ExecutionConfig::batchLimit` (default: 500) controls how many records `plan()`
reads in a single call. Processing is done in bounded batches to control memory.

## Re-injection

Controlled protocol re-injection is a future, secondary, and higher-risk capability.
It is explicitly not part of this release.

`ExecutionConfig::reinjectionEnabled` must remain `false`. Setting it to `true`
throws `\InvalidArgumentException`. This guard will be removed only in the release
that implements the full guarded re-injection path.

## Current implementation status

| Feature | Status |
|---|---|
| plan() — read from cursor | Implemented |
| execute() — dry-run mode | Implemented |
| execute() — observational mode | Implemented |
| Handler dispatch | Not yet implemented (Phase 7) |
| Re-injection | Not yet implemented (Phase 10) |

See `docs/stability-policy.md` for the implementation phase roadmap.
