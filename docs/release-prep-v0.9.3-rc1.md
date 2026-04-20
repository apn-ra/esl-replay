# Release Prep — v0.9.3-rc1

## Scope

Prepare a release candidate cut for the completed hardening work on the current
audited package surface.

This document is historical for the hardening RC. The current release-prep
target after adding bounded recovery/evidence reconstruction is
`docs/release-prep-v0.9.4-rc1.md`.

This RC covers:
- fail-closed empty-checkpoint prune query behavior
- collision-safe filesystem checkpoint filenames with legacy lookup compatibility
- filesystem vs. SQLite `storagePath` documentation semantics
- SQLite writer-model truth narrowed to the currently supported single-writer-epoch posture
- filesystem retention/write coordination through `artifacts.ndjson.lock`
- checksum semantics clarified as consumer-invoked verification
- stable `OperatorIdentityKeys` cross-package constants
- fail-loud unreadable existing artifact recovery
- package-level filesystem single-writer ownership through
  `artifacts.ndjson.writer.lock`
- release-facing documentation, changelog, PHPUnit config, and package metadata polish

This RC does not include:
- checksum-verifying reader APIs
- PostgreSQL support
- non-filesystem retention backends
- SQLite concurrency redesign
- live runtime/session lifecycle ownership
- reconnect or liveness supervision
- Laravel integration
- business telephony orchestration

## Release-truth checklist

- [x] `CHANGELOG.md` summarizes the hardening work without claiming new runtime behavior
- [x] `README.md` matches the current implemented scope and adapter path semantics
- [x] `docs/public-api.md` reflects the current stable surface
- [x] `docs/storage-model.md` states:
  - [x] ordinary filesystem reads may skip malformed persisted lines by design
  - [x] retention/rewrite flows are stricter and fail on malformed retained input
  - [x] filesystem retention/write coordination uses `artifacts.ndjson.lock`
  - [x] filesystem writer ownership uses `artifacts.ndjson.writer.lock`
  - [x] SQLite writer semantics are documented conservatively for this RC
  - [x] PostgreSQL remains future work
- [x] `docs/checkpoint-model.md` keeps recovery defined as persisted-artifact progress recovery only
- [x] `docs/retention-policy.md` reflects filesystem-backed retention only
- [x] `docs/stability-policy.md` reflects RC posture for the next hardening release candidate

## RC verification checklist

- [x] `composer validate --strict`
- [x] `vendor/bin/phpunit`
- [x] `vendor/bin/phpstan analyse`
- [x] `git diff --check`

## RC notes

- `v0.9.3-rc1` is the next RC target after the existing `v0.9.0`,
  `v0.9.1`, and `v0.9.2` tags.
- The opt-in live harness at `tools/live/run_live_suite.php` may be used for
  pre-release verification against a real FreeSWITCH PBX. It is support-only
  and remains outside the default PHPUnit/CI flow.
- This RC is a release-hardening cut. It does not add a checksum-verifying
  reader API, broader storage redesign, or live runtime ownership.
