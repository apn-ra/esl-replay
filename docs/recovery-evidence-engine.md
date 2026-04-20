# Recovery Evidence Engine

## Purpose

`apntalk/esl-replay` now acts as the package-family durable recovery and
evidence engine over stored artifacts.

This means it can:

- consume richer runtime-truth metadata emitted by newer `apntalk/esl-react`
  releases through stored payload/runtime metadata
- reconstruct bounded runtime truth from append-ordered stored records
- emit deterministic evidence bundles and comparison results
- support scenario-oriented recovery evidence such as SC19-style audits

It still does **not**:

- restore a live FreeSWITCH socket
- restore a live ESL session
- own reconnect supervision
- claim live continuity after restart

## Input truth surfaces

The recovery/evidence engine consumes stored artifacts only.

Supported additive truth surfaces are read from preserved serialized metadata
such as:

- `payload.prepared_recovery_context`
- `payload.runtime_recovery_snapshot`
- `payload.runtime_operation_snapshot`
- `payload.runtime_terminal_publication_snapshot`
- `payload.runtime_lifecycle_semantic_snapshot`
- `payload.replay_metadata`
- `runtime_flags`
- `correlation_ids`
- checkpoint `metadata`

No hard dependency on `apntalk/esl-react` types is required. The engine reads
serialized fields already preserved by the storage layer.

## Core model

The engine keeps four layers distinct:

1. captured artifact envelope
2. stored replay record
3. reconstruction/evidence projection
4. execution/reinjection candidate

It does not collapse these into one type.

## Main public surfaces

- `RecoveryMetadataKeys`
- `ReconstructionWindow`
- `CheckpointReconstructionWindowResolver`
- `RecoveryEvidenceEngine`
- `RecoveryManifest`
- `RecoveryGenerationObservation`
- `ReconstructionVerdict`
- `ReconstructionIssue`
- `RuntimeContinuitySnapshot`
- `OperationRecoveryRecord`
- `TerminalPublicationEvidenceRecord`
- `LifecycleSemanticEvidenceRecord`
- `EvidenceRecordReference`
- `EvidenceBundle`
- `ScenarioExpectation`
- `ExpectedOperationLifecycle`
- `ExpectedTerminalPublication`
- `ExpectedLifecycleSemantic`
- `ScenarioComparisonResult`

## Reconstruction rules

Reconstruction:

- starts from a `ReplayReadCursor`
- consumes records in append-sequence order
- may be bounded by `untilAppendSequence`
- may apply ordinary `ReplayReadCriteria`
- produces explicit insufficiency/ambiguity issues when stored truth is absent
  or contradictory

Fail-closed examples:

- operation-state evidence without a stable `operation_id`
- terminal-publication evidence without publication identity and status
- lifecycle-semantic evidence without semantic name and posture
- checkpoint generation/session metadata that contradict the next visible record

## Deterministic evidence output

`EvidenceBundleSerializer` produces deterministic JSON output:

- fixed key ordering
- append-ordered lists preserved
- associative facts/details recursively key-sorted
- bundle identity derived from bundle content

This makes exported bundles suitable for downstream governance inspection
without turning this package into a governance framework.

## SC19-style scenario comparison

`ScenarioExpectation` and `ScenarioComparisonResult` provide a generic
comparison surface for scenario-oriented recovery evidence.

Current comparison coverage includes:

- expected recovery generation sequence
- expected replay continuity posture
- expected retry/drain/reconstruction posture
- expected operation lifecycle state sequence
- expected terminal-publication evidence
- expected lifecycle-semantic evidence

The comparator remains generic; governance policy and repository governance
files remain outside this package.
