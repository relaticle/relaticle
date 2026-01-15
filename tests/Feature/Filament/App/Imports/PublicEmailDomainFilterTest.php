<?php

declare(strict_types=1);

use Relaticle\ImportWizard\Support\PublicEmailDomainFilter;

beforeEach(function (): void {
    // Ensure domains file exists for tests
    $path = dirname(__DIR__, 5).'/app-modules/ImportWizard/storage/free-email-domains.json';
    if (! file_exists($path)) {
        file_put_contents($path, json_encode(['gmail.com', 'outlook.com', 'yahoo.com']));
    }

    // Reset config to defaults
    config()->set('import-wizard.public_email_domains.enabled', true);
    config()->set('import-wizard.public_email_domains.path', null);
});

describe('PublicEmailDomainFilter', function (): void {
    it('identifies public domains', function (string $domain): void {
        $filter = new PublicEmailDomainFilter;
        expect($filter->isPublicDomain($domain))->toBeTrue();
    })->with(['gmail.com', 'GMAIL.COM', 'outlook.com', 'yahoo.com', 'hotmail.com']);

    it('identifies business domains as non-public', function (string $domain): void {
        $filter = new PublicEmailDomainFilter;
        expect($filter->isPublicDomain($domain))->toBeFalse();
    })->with(['acme.com', 'company.org', 'mycompany.io', 'relaticle.com']);

    it('filters public domains from array', function (): void {
        $filter = new PublicEmailDomainFilter;
        $domains = ['acme.com', 'gmail.com', 'company.org', 'outlook.com'];

        $filtered = $filter->filterDomains($domains);

        expect($filtered)->toBe(['acme.com', 'company.org']);
    });

    it('returns all domains when filtering is disabled', function (): void {
        config()->set('import-wizard.public_email_domains.enabled', false);

        $filter = new PublicEmailDomainFilter;

        expect($filter->isPublicDomain('gmail.com'))->toBeFalse()
            ->and($filter->isEnabled())->toBeFalse();
    });

    it('handles empty array', function (): void {
        $filter = new PublicEmailDomainFilter;
        expect($filter->filterDomains([]))->toBe([]);
    });

    it('normalizes domain case and whitespace', function (): void {
        $filter = new PublicEmailDomainFilter;

        expect($filter->isPublicDomain(' GMAIL.COM '))->toBeTrue()
            ->and($filter->isPublicDomain('Gmail.Com'))->toBeTrue()
            ->and($filter->isPublicDomain('  outlook.com'))->toBeTrue();
    });

    it('returns domain count', function (): void {
        $filter = new PublicEmailDomainFilter;

        expect($filter->getDomainCount())->toBeGreaterThan(100);
    });

    it('preserves array indices after filtering', function (): void {
        $filter = new PublicEmailDomainFilter;
        $domains = ['gmail.com', 'acme.com', 'yahoo.com', 'business.io'];

        $filtered = $filter->filterDomains($domains);

        expect($filtered)->toBe(['acme.com', 'business.io'])
            ->and(array_keys($filtered))->toBe([0, 1]);
    });
});

describe('CompanyMatcher with Public Email Filtering', function (): void {
    it('filters public domains during automatic matching', function (): void {
        $this->actingAs($user = \App\Models\User::factory()->withPersonalTeam()->create());
        $team = $user->personalTeam();

        $matcher = new \Relaticle\ImportWizard\Support\CompanyMatcher;

        // With only public email (gmail) and company name - should create new (no domain match)
        $result = $matcher->match('', 'Some Company', ['john@gmail.com'], (string) $team->id);

        // gmail.com is filtered, so no domain match possible - falls through to MatchType::New
        expect($result->matchType)->toBe(\Relaticle\ImportWizard\Enums\MatchType::New)
            ->and($result->companyName)->toBe('Some Company');
    });

    it('does not filter when filterPublicDomains is false', function (): void {
        $this->actingAs($user = \App\Models\User::factory()->withPersonalTeam()->create());
        $team = $user->personalTeam();

        $matcher = new \Relaticle\ImportWizard\Support\CompanyMatcher;

        // With filterPublicDomains: false, gmail.com domain is NOT filtered
        // Since no company exists with gmail.com domain, it will still be New, but the domain was checked
        $result = $matcher->match('', 'Test Company', ['test@gmail.com'], (string) $team->id, filterPublicDomains: false);

        // Still MatchType::New because no company has gmail.com domain, but the domain WAS checked
        expect($result->matchType)->toBe(\Relaticle\ImportWizard\Enums\MatchType::New);
    });

    it('returns MatchType::None when only public email and no company name', function (): void {
        $this->actingAs($user = \App\Models\User::factory()->withPersonalTeam()->create());
        $team = $user->personalTeam();

        $matcher = new \Relaticle\ImportWizard\Support\CompanyMatcher;

        // With only public email (gmail) and NO company name - should return None
        $result = $matcher->match('', '', ['john@gmail.com'], (string) $team->id);

        // gmail.com is filtered, and no company name provided = no association
        expect($result->matchType)->toBe(\Relaticle\ImportWizard\Enums\MatchType::None);
    });
});
