<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Relaticle\Chat\Enums\WriteGuard;

/**
 * What a probe learned about a catalog entry, or nothing at all.
 *
 * Absence is the point. "Nobody has measured this pairing" and "the provider
 * will not serve tools on it" are different claims with different consequences,
 * and the catalog used to carry them as two loose fields (`capabilities`,
 * `verified_at`) that could disagree: a `verified_at` with no capabilities beside
 * it says a probe ran and measured nothing, which never happens. One nullable
 * object makes that unrepresentable.
 *
 * `verifiedAt` is nullable inside a present measurement because a config-seeded
 * entry declares capabilities no probe on THIS install has confirmed. That is a
 * weaker claim than a measured one, and the panel's Verified column says so.
 *
 * Kept as a string rather than a date: the settings row is editable outside the
 * panel, and an unparseable timestamp must not take a page down.
 */
final readonly class Measurement
{
    public function __construct(
        public bool $supportsTools,
        public WriteGuard $writeGuard,
        public ?string $verifiedAt = null,
    ) {}

    /**
     * Reads a stored entry's measurement, or null when it carries none.
     *
     * Fails closed on an unreadable guard: claiming `api` without proof would
     * tell the write path the provider refuses parallel tool calls when it may
     * not.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromEntry(array $entry): ?self
    {
        $capabilities = $entry['capabilities'] ?? null;

        if (! is_array($capabilities) || $capabilities === []) {
            return null;
        }

        $verifiedAt = $entry['verified_at'] ?? null;

        return new self(
            supportsTools: ($capabilities['supports_tools'] ?? false) === true,
            writeGuard: WriteGuard::tryFrom((string) ($capabilities['write_guard'] ?? '')) ?? WriteGuard::Prompt,
            verifiedAt: is_string($verifiedAt) && $verifiedAt !== '' ? $verifiedAt : null,
        );
    }

    /**
     * @return array{supports_tools: bool, write_guard: string}
     */
    public function toCapabilities(): array
    {
        return [
            'supports_tools' => $this->supportsTools,
            'write_guard' => $this->writeGuard->value,
        ];
    }
}
