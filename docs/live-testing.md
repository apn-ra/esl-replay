# Live Testing

## Purpose

`apntalk/esl-replay` includes an opt-in live verification harness at
[tools/live/run_live_suite.php](/home/grimange/apn_projects/esl-replay/tools/live/run_live_suite.php).

This harness exists to validate the current replay package surface against a
real FreeSWITCH PBX using live-derived artifacts. It is intended for:

- RC validation
- pre-release verification
- manual support verification
- environment sanity checks against a real PBX

It is not part of the default PHPUnit flow and it is not part of normal CI.

## What it does

The harness:

- reads ESL connection settings from `.env.live.local`
- opens a minimal inbound ESL session to a real FreeSWITCH PBX
- runs a narrow live exchange using safe commands:
  - `api status`
  - `bgapi status`
  - `event plain BACKGROUND_JOB`
- captures the resulting live-derived artifacts
- validates the replay package surface on those stored artifacts:
  - filesystem persistence and reopen behavior
  - bounded reader filtering
  - checkpoint save/load/resume
  - offline replay dry-run and observational execution
  - guarded reinjection behavior on live-derived records
  - SQLite parity on the same live-derived fixture set

`telecom_mcp` may be used as an optional PBX-side cross-check for health,
version, and observed event presence. It does not replace the package live
harness.

## What it does not do

The harness does not:

- become part of the production package runtime
- make CI depend on live PBX credentials
- manage live session lifecycle or reconnect behavior
- test or imply live-session restoration semantics
- broaden re-injection beyond the current guarded package rules

## Credentials and safety

The harness reads credentials only from `.env.live.local`.

Required variables:

- `ESL_REPLAY_LIVE_HOST`
- `ESL_REPLAY_LIVE_PORT`
- `ESL_REPLAY_LIVE_PASSWORD`

Rules:

- do not print raw secret values
- do not copy secrets into docs, fixtures, or commits
- report only missing variable names when config is incomplete
- keep failures descriptive without exposing credentials

## How to run it

```bash
php tools/live/run_live_suite.php
```

For usage help:

```bash
php tools/live/run_live_suite.php --help
```

The script emits a JSON summary of the live verification result and exits
non-zero on failure.
