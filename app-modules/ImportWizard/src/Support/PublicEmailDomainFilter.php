<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

/**
 * Filters out public/free email provider domains from automatic company matching.
 *
 * Prevents false associations where personal emails (john@gmail.com) would
 * match companies with generic domains.
 *
 * Domain list is sourced from Kikobeats/free-email-domains (based on HubSpot's list).
 * Update with: php artisan import-wizard:update-email-domains
 */
final class PublicEmailDomainFilter
{
    /** @var array<string, true>|null Cached domain lookup map */
    private ?array $domainMap = null;

    private readonly bool $enabled;

    private readonly string $domainsPath;

    public function __construct()
    {
        $this->enabled = (bool) config('import-wizard.public_email_domains.enabled', true);

        $configPath = config('import-wizard.public_email_domains.path');
        $this->domainsPath = is_string($configPath) && $configPath !== ''
            ? $configPath
            : dirname(__DIR__, 2).'/storage/free-email-domains.json';
    }

    /**
     * Check if a domain is a public/free email provider.
     */
    public function isPublicDomain(string $domain): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $this->ensureDomainsLoaded();

        return isset($this->domainMap[strtolower(trim($domain))]);
    }

    /**
     * Filter out public email domains from a list of domains.
     *
     * @param  array<string>  $domains
     * @return array<string>
     */
    public function filterDomains(array $domains): array
    {
        if (! $this->enabled) {
            return $domains;
        }

        $this->ensureDomainsLoaded();

        return array_values(array_filter(
            $domains,
            fn (string $domain): bool => ! isset($this->domainMap[strtolower(trim($domain))])
        ));
    }

    /**
     * Check if filtering is currently enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the count of loaded domains.
     */
    public function getDomainCount(): int
    {
        $this->ensureDomainsLoaded();

        return count($this->domainMap ?? []);
    }

    /**
     * Load domains from JSON file into memory as a hash map for O(1) lookup.
     */
    private function ensureDomainsLoaded(): void
    {
        if ($this->domainMap !== null) {
            return;
        }

        $this->domainMap = [];

        if (! file_exists($this->domainsPath)) {
            return;
        }

        $content = file_get_contents($this->domainsPath);
        if ($content === false) {
            return;
        }

        $domains = json_decode($content, true);
        if (! is_array($domains)) {
            return;
        }

        // Build hash map for O(1) lookup
        foreach ($domains as $domain) {
            if (is_string($domain) && $domain !== '') {
                $this->domainMap[strtolower(trim($domain))] = true;
            }
        }
    }
}
