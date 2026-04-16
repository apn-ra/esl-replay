<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Storage;

/**
 * Immutable identifier for a stored replay record.
 *
 * Stored as a UUID v4 string. Generated at write time by the storage layer.
 * Once assigned, a record ID never changes.
 */
final readonly class ReplayRecordId
{
    public function __construct(public readonly string $value)
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException('ReplayRecordId must not be empty.');
        }
    }

    /**
     * Generate a new random record ID (UUID v4).
     */
    public static function generate(): self
    {
        $bytes = random_bytes(16);
        // Set version 4
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        // Set variant 10xx
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        $hex = bin2hex($bytes);
        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );

        return new self($uuid);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
