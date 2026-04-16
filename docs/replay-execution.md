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
records as `dry_run_skip` and produces no side effects. If a handler registry is
configured, dry-run reports whether a matching handler would be dispatched, but
it still does not invoke handlers. `processedCount` will be 0 and
`skippedCount` will equal the number of records in the plan.

Dry-run is the safe default. It allows inspecting what would be executed before
committing.

## Live/observational mode

When `ExecutionConfig::dryRun` is `false`, `execute()` processes records and
records outcomes. If a matching handler is registered for an artifact name, the
executor dispatches that handler explicitly and records its `ReplayHandlerResult`
action and metadata. If no handler is registered, the record remains
observational and is marked `observed`.

Handler dispatch is bounded:
- resolution is exact artifact-name matching only
- records are processed in append-sequence order
- dry-run never invokes handlers
- handler failures return a failed `OfflineReplayResult` with the error message

## Batch limit

`ExecutionConfig::batchLimit` (default: 500) controls how many records `plan()`
reads in a single call. Processing is done in bounded batches to control memory.

## Re-injection

Controlled protocol re-injection is now available as a separate, higher-risk
mode. It remains secondary to the package’s core identity and is guarded by all
of the following:

- `ExecutionConfig::reinjectionEnabled` must be set to `true`
- `ExecutionConfig::reinjectionArtifactAllowlist` must be non-empty
- a caller-supplied `ReplayInjectorInterface` implementation must be provided
- only artifact types that are both intrinsically executable and allowlisted
  become `ReplayExecutionCandidate` values

Current intrinsic executable types:
- `api.dispatch`
- `bgapi.dispatch`

Observational artifacts such as `event.raw`, `command.reply`, `api.reply`,
`bgapi.ack`, and `bgapi.complete` are rejected clearly and are not injected.

Dry-run remains side-effect free. In dry-run, replay reports whether a record
would be reinjected, but it never invokes the injector.

If a caller-supplied injector throws during execute mode, replay returns a failed
`OfflineReplayResult` with partial prior outcomes rather than silently masking
the failure.

## Current implementation status

| Feature | Status |
|---|---|
| plan() — read from cursor | Implemented |
| execute() — dry-run mode | Implemented |
| execute() — observational mode | Implemented |
| Handler dispatch | Implemented |
| Re-injection | Implemented as an explicit guarded mode |

See `docs/stability-policy.md` for the implementation phase roadmap.
