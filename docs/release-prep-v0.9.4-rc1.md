# Release Prep — v0.9.4-rc1

This document is historical for the `v0.9.4-rc1` release-candidate pass.
The shipped stable release for this scope is `v0.9.4`.

## Scope

Prepare a release candidate cut for the additive recovery/evidence engine track.

This RC covers:

- deterministic reconstruction of bounded runtime truth from stored artifacts
- deterministic evidence bundle and scenario comparison JSON export
- additive checkpoint metadata anchors and lookup for `recovery_generation_id`
- package-local consumption of richer `esl-react` runtime-truth metadata through
  existing stored object fields
- release-facing docs updates for the new durable recovery/evidence role

This RC does not include:

- live socket/session restoration semantics
- reconnect supervision
- live backpressure ownership
- Laravel integration
- governance-policy execution
- multi-PBX orchestration policy
- schema version bump beyond `schema_version: 1`

## Release-truth checklist

- [x] `CHANGELOG.md` describes the additive recovery/evidence surface truthfully
- [x] `README.md` reflects bounded recovery/evidence reconstruction without
  claiming live runtime ownership
- [x] `docs/public-api.md` lists the additive stable DTOs and engine surfaces
- [x] `docs/artifact-schema.md` states that richer runtime truth remains
  additive within schema version 1
- [x] `docs/checkpoint-model.md` keeps checkpoints defined as artifact-processing
  progress only
- [x] `docs/recovery-evidence-engine.md` explains the new model and fail-closed
  semantics
- [x] `docs/stability-policy.md` reflects the new RC posture

## RC verification checklist

- [x] `composer validate --strict`
- [x] `vendor/bin/phpstan analyse`
- [x] `vendor/bin/phpunit`
- [x] `git diff --check`

## RC notes

- `v0.9.4-rc1` is the correct next RC target for the post-hardening recovery
  and evidence engine track.
- The recovery/evidence engine is auditable and deterministic over stored
  artifacts, but it still does not close live runtime recovery by itself.
- Opt-in live verification remains support-only and outside default CI.
