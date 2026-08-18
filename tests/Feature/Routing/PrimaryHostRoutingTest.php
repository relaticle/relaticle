<?php

declare(strict_types=1);

use App\Http\Middleware\DenyIndexingOnSecondaryHosts;
use App\Http\Middleware\RedirectToPrimaryHost;
use App\Models\User;

mutates(RedirectToPrimaryHost::class, DenyIndexingOnSecondaryHosts::class);

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

describe('app plumbing on secondary hosts', function () {
    it('leaves POST requests untouched', function (): void {
        $this->post('http://app.relaticle.test/logout')
            ->assertStatus(302)
            ->assertRedirect('http://app.relaticle.test/login');
    });

    it('leaves authenticated GET routes untouched', function (): void {
        $user = User::factory()->withPersonalTeam()->unverified()->create();

        $primaryStatus = $this->actingAs($user)->get('http://relaticle.test/email/verify')->status();
        $secondary = $this->actingAs($user)->get('http://app.relaticle.test/email/verify');

        expect($secondary->status())->toBe($primaryStatus)->not->toBe(301);
    });

    it('leaves signed routes untouched so host-bound signatures fail loudly instead of silently breaking', function (): void {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('http://app.relaticle.test/team-invitations/01hxxxxxxxxxxxxxxxxxxxxxxx?signature=invalid')
            ->assertForbidden();
    });

    it('leaves the broadcasting auth endpoint untouched', function (): void {
        $primaryStatus = $this->get('http://relaticle.test/broadcasting/auth')->status();
        $secondary = $this->get('http://app.relaticle.test/broadcasting/auth');

        expect($secondary->status())->toBe($primaryStatus)->not->toBe(301);
    });
});

describe('indexing on secondary hosts', function () {
    it('marks every secondary-host response noindex', function (): void {
        $this->get('http://sysadmin.relaticle.test/pricing')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    });
});
