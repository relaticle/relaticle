<?php

declare(strict_types=1);

use App\Http\Middleware\RedirectToPrimaryHost;

mutates(RedirectToPrimaryHost::class);

describe('public pages on secondary hosts', function () {
    it('permanently redirects a marketing page to the primary host', function (): void {
        $this->get('http://sysadmin.relaticle.test/pricing')
            ->assertStatus(301)
            ->assertRedirect('http://relaticle.test/pricing');
    });

    it('preserves the path and query string when redirecting', function (): void {
        $this->get('http://api.relaticle.test/compare/relaticle-vs-espocrm?utm_source=newsletter')
            ->assertRedirect('http://relaticle.test/compare/relaticle-vs-espocrm?utm_source=newsletter');
    });

    it('redirects documentation pages', function (): void {
        $this->get('http://mcp.relaticle.test/help')
            ->assertRedirect('http://relaticle.test/help');
    });

    it('redirects on any unrecognised subdomain, not only configured ones', function (): void {
        $this->get('http://landing-typo.relaticle.test/pricing')
            ->assertRedirect('http://relaticle.test/pricing');
    });

    it('serves public pages normally on the primary host', function (): void {
        $this->get('http://relaticle.test/pricing')
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag');
    });
});
