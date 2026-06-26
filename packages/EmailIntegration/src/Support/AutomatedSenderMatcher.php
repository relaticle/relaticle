<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

/**
 * Detects machine-sent addresses (no-reply@, notice@, bounce@, …) so email sync
 * doesn't auto-create a Company or Person for mail with no real contact behind it.
 *
 * The local-part (before the @) is matched as a case-insensitive substring against
 * the configured list. Existing-record matching/linking is unaffected — this only
 * gates *creation*.
 */
final readonly class AutomatedSenderMatcher
{
    public function matches(string $emailAddress): bool
    {
        $localPart = mb_strtolower((string) strstr($emailAddress, '@', true));

        if ($localPart === '') {
            return false;
        }

        /** @var list<string> $patterns */
        $patterns = (array) config('email-integration.automated_local_parts', []);

        // ponytail: substring match (mirrors EmailClassifier). A rare real local-part
        // that contains a pattern would be skipped; tighten to token match if it bites.
        return array_any($patterns, fn (string $pattern): bool => str_contains($localPart, mb_strtolower($pattern)));
    }
}
