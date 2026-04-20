<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Artifact;

/**
 * The input contract for artifacts emitted by apntalk/esl-react.
 *
 * This interface represents the boundary between the live runtime layer
 * (esl-react) and the durable storage layer (esl-replay). The package
 * accepts anything implementing this interface and persists it without
 * altering its semantic meaning.
 *
 * Known artifact names emitted by esl-react:
 *   api.dispatch, api.reply, bgapi.dispatch, bgapi.ack, bgapi.complete,
 *   command.reply, event.raw, subscription.mutate, filter.mutate
 *
 * This package does not validate artifact names against a fixed allowlist.
 * New artifact types introduced by esl-react are stored transparently.
 */
interface CapturedArtifactEnvelope
{
    /**
     * The replay artifact schema version as captured by esl-react.
     * Preserved exactly — never upgraded silently during write.
     */
    public function getArtifactVersion(): string;

    /**
     * The artifact name as captured (e.g. "api.dispatch", "event.raw").
     * Preserved exactly — never reinterpreted during write.
     */
    public function getArtifactName(): string;

    /**
     * The timestamp at which esl-react captured this artifact.
     * Must be in UTC.
     */
    public function getCaptureTimestamp(): \DateTimeImmutable;

    /**
     * The runtime capture path if recorded by esl-react (e.g. session path).
     */
    public function getCapturePath(): ?string;

    /**
     * A monotonic connection generation counter from esl-react, if present.
     */
    public function getConnectionGeneration(): ?string;

    /**
     * The ESL session identifier at capture time, if present.
     */
    public function getSessionId(): ?string;

    /**
     * The background job UUID associated with this artifact, if present.
     */
    public function getJobUuid(): ?string;

    /**
     * The FreeSWITCH event name, if this artifact carries one.
     */
    public function getEventName(): ?string;

    /**
     * Correlation identifiers that link this artifact to related artifacts.
     * Operator identity keys shared with esl-react are published in
     * OperatorIdentityKeys.
     *
     * @return array<string, string>
     */
    public function getCorrelationIds(): array;

    /**
     * Runtime flags recorded by esl-react at capture time.
     * Operator identity keys shared with esl-react are published in
     * OperatorIdentityKeys.
     *
     * @return array<string, mixed>
     */
    public function getRuntimeFlags(): array;

    /**
     * The raw artifact payload as captured by esl-react.
     * Stored verbatim — never reinterpreted or mutated during persistence.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array;
}
