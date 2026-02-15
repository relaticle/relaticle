<?php

declare(strict_types=1);

use Filament\Facades\Filament;

describe('app panel configuration - path mode (default)', function () {
    it('registers panel with path prefix and no domain constraint', function () {
        $panel = Filament::getPanel('app');

        expect($panel->getDomains())->toBeEmpty()
            ->and($panel->getPath())->toBe(config('app.app_panel_path', 'app'));
    });

    it('serves login page at the panel path', function () {
        $panelPath = config('app.app_panel_path', 'app');

        $this->get("/{$panelPath}/login")->assertOk();
    });
});

describe('getAppUrl macro - path mode', function () {
    beforeEach(function () {
        config([
            'app.app_panel_domain' => null,
            'app.app_panel_path' => 'app',
            'app.url' => 'https://example.com',
        ]);
    });

    it('returns path-based URL with default path', function () {
        expect(url()->getAppUrl('login'))->toBe('https://example.com/app/login')
            ->and(url()->getAppUrl())->toBe('https://example.com/app');
    });

    it('handles custom panel path', function () {
        config(['app.app_panel_path' => 'crm']);

        expect(url()->getAppUrl('login'))->toBe('https://example.com/crm/login');
    });

    it('handles port in APP_URL for Docker deployments', function () {
        config(['app.url' => 'http://localhost:8080']);

        expect(url()->getAppUrl('login'))->toBe('http://localhost:8080/app/login');
    });

    it('handles IP address with port', function () {
        config(['app.url' => 'http://192.168.1.100:8080']);

        expect(url()->getAppUrl('dashboard'))->toBe('http://192.168.1.100:8080/app/dashboard');
    });

    it('handles nested path segments', function () {
        expect(url()->getAppUrl('teams/1/companies'))->toBe('https://example.com/app/teams/1/companies');
    });

    it('handles path with leading slash', function () {
        expect(url()->getAppUrl('/login'))->toBe('https://example.com/app/login');
    });

    it('handles http scheme', function () {
        config(['app.url' => 'http://example.com']);

        expect(url()->getAppUrl('login'))->toBe('http://example.com/app/login');
    });

    it('strips trailing slash from APP_URL', function () {
        config(['app.url' => 'https://example.com/']);

        expect(url()->getAppUrl('login'))->toBe('https://example.com/app/login');
    });
});

describe('getAppUrl macro - domain mode', function () {
    beforeEach(function () {
        config([
            'app.app_panel_domain' => 'app.example.com',
            'app.url' => 'https://example.com',
        ]);
    });

    it('returns domain-based URL when APP_PANEL_DOMAIN is set', function () {
        expect(url()->getAppUrl('login'))->toBe('https://app.example.com/login')
            ->and(url()->getAppUrl())->toBe('https://app.example.com');
    });

    it('uses scheme from APP_URL', function () {
        config([
            'app.app_panel_domain' => 'crm.example.com',
            'app.url' => 'http://example.com',
        ]);

        expect(url()->getAppUrl('login'))->toBe('http://crm.example.com/login');
    });

    it('handles nested path segments', function () {
        expect(url()->getAppUrl('teams/1/companies'))->toBe('https://app.example.com/teams/1/companies');
    });

    it('handles path with leading slash', function () {
        expect(url()->getAppUrl('/login'))->toBe('https://app.example.com/login');
    });

    it('preserves port from APP_URL', function () {
        config([
            'app.app_panel_domain' => 'app.localhost',
            'app.url' => 'http://localhost:8080',
        ]);

        expect(url()->getAppUrl('login'))->toBe('http://app.localhost:8080/login')
            ->and(url()->getAppUrl())->toBe('http://app.localhost:8080');
    });
});

describe('getPublicUrl macro', function () {
    it('returns APP_URL based URL', function () {
        config(['app.url' => 'https://example.com']);

        expect(url()->getPublicUrl('about'))->toBe('https://example.com/about');
    });

    it('returns clean base URL when path is empty', function () {
        config(['app.url' => 'https://example.com']);

        expect(url()->getPublicUrl())->toBe('https://example.com');
    });

    it('preserves port in URL', function () {
        config(['app.url' => 'http://localhost:8080']);

        expect(url()->getPublicUrl('about'))->toBe('http://localhost:8080/about');
    });

    it('is not affected by app panel domain config', function () {
        config([
            'app.app_panel_domain' => 'app.example.com',
            'app.url' => 'https://example.com',
        ]);

        expect(url()->getPublicUrl('about'))->toBe('https://example.com/about');
    });
});
