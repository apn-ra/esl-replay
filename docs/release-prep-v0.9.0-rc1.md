# Release Prep — v0.9.0-rc1

## Scope

Prepare a release candidate cut for the current audited package surface only.

This RC covers:
- deterministic storage and reads
- filesystem append/read/restart behavior
- checkpointed persisted-artifact progress recovery
- bounded reader filtering
- handler-driven offline replay
- filesystem-backed retention coordination
- SQLite contract parity
- guarded optional re-injection
- hardening plus aggressive chaos coverage

This RC does not include:
- PostgreSQL support
- live runtime/session lifecycle ownership
- reconnect or liveness supervision
- Laravel integration
- business telephony orchestration

## Release-truth checklist

- [x] `README.md` matches current implemented scope
- [x] `docs/public-api.md` matches the current stable surface
- [x] `docs/storage-model.md` states:
  - [x] ordinary filesystem reads may skip malformed persisted lines by design
  - [x] retention/rewrite flows are stricter and fail on malformed retained input
  - [x] PostgreSQL remains future work
- [x] `docs/checkpoint-model.md` keeps recovery defined as persisted-artifact progress recovery only
- [x] `docs/replay-execution.md` keeps re-injection optional, disabled by default, and higher risk
- [x] `docs/retention-policy.md` reflects filesystem-backed retention only
- [x] `docs/stability-policy.md` reflects the audited frozen surface
- [x] `CHANGELOG.md` contains RC-oriented release notes and the late-cycle hostile-path fixes
- [x] `docs/implementation-progress.md` still matches the completed roadmap state

## RC verification checklist

- [x] `composer validate --strict`
- [x] `composer install --no-interaction`
- [x] `vendor/bin/phpstan analyse`
- [x] `vendor/bin/phpunit`
- [x] Filesystem chaos suite rerun
- [x] Checkpoint chaos suite rerun
- [x] Retention chaos suite rerun
- [x] Guarded re-injection chaos suite rerun
- [x] SQLite parity/corruption suite rerun

## RC notes

- `v0.9.0-rc1` is recommended ahead of final `v0.9.0` because hostile-path behavior changed late in cycle.
- The three late-cycle fixes are:
  - stale filesystem `byteOffsetHint` past EOF no longer hides valid records
  - retention rewrite now fails explicitly on malformed retained input
  - reinjection injector exceptions now return failed replay results instead of bubbling unexpectedly
