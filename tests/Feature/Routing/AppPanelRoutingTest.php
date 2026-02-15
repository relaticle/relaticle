<?php

declare(strict_types=1);

describe('getAppUrl macro - path mode', function () {
    beforeEach(function () {
        config(['app.app_panel_domain' => null]);
    });

    it('returns path-based URL with default path', function () {
        config(['app.app_panel_path' => 'app']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('login'))->toBe('https://example.com/app/login')
            ->and(url()->getAppUrl())->toBe('https://example.com/app/');
    });

    it('handles custom panel path', function () {
        config(['app.app_panel_path' => 'crm']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('login'))->toBe('https://example.com/crm/login');
    });

    it('handles port in APP_URL for Docker deployments', function () {
        config(['app.app_panel_path' => 'app']);
        config(['app.url' => 'http://localhost:8080']);

        expect(url()->getAppUrl('login'))->toBe('http://localhost:8080/app/login');
    });

    it('handles IP address with port', function () {
        config(['app.app_panel_path' => 'app']);
        config(['app.url' => 'http://192.168.1.100:8080']);

        expect(url()->getAppUrl('dashboard'))->toBe('http://192.168.1.100:8080/app/dashboard');
    });

    it('handles nested path segments', function () {
        config(['app.app_panel_path' => 'app']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('teams/1/companies'))->toBe('https://example.com/app/teams/1/companies');
    });

    it('handles path with leading slash', function () {
        config(['app.app_panel_path' => 'app']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('/login'))->toBe('https://example.com/app/login');
    });

    it('handles http scheme', function () {
        config(['app.app_panel_path' => 'app']);
        config(['app.url' => 'http://example.com']);

        expect(url()->getAppUrl('login'))->toBe('http://example.com/app/login');
    });

    it('strips trailing slash from APP_URL', function () {
        config(['app.app_panel_path' => 'app']);
        config(['app.url' => 'https://example.com/']);

        expect(url()->getAppUrl('login'))->toBe('https://example.com/app/login');
    });
});

describe('getAppUrl macro - domain mode', function () {
    it('returns domain-based URL when APP_PANEL_DOMAIN is set', function () {
        config(['app.app_panel_domain' => 'app.example.com']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('login'))->toBe('https://app.example.com/login')
            ->and(url()->getAppUrl())->toBe('https://app.example.com/');
    });

    it('uses scheme from APP_URL', function () {
        config(['app.app_panel_domain' => 'crm.example.com']);
        config(['app.url' => 'http://example.com']);

        expect(url()->getAppUrl('login'))->toBe('http://crm.example.com/login');
    });

    it('handles nested path segments', function () {
        config(['app.app_panel_domain' => 'app.example.com']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('teams/1/companies'))->toBe('https://app.example.com/teams/1/companies');
    });

    it('handles path with leading slash', function () {
        config(['app.app_panel_domain' => 'app.example.com']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('/login'))->toBe('https://app.example.com/login');
    });
});

describe('getPublicUrl macro', function () {
    it('returns APP_URL based URL', function () {
        config(['app.url' => 'https://example.com']);

        expect(url()->getPublicUrl('about'))->toBe('https://example.com/about');
    });

    it('is not affected by app panel domain config', function () {
        config(['app.app_panel_domain' => 'app.example.com']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getPublicUrl('about'))->toBe('https://example.com/about');
    });
});

describe('authentication redirects', function () {
    it('redirects /login to app panel', function () {
        $response = test()->get('/login');

        $response->assertRedirect(url()->getAppUrl('login'));
    });

    it('redirects /register to app panel', function () {
        $response = test()->get('/register');

        $response->assertRedirect(url()->getAppUrl('register'));
    });

    it('redirects /forgot-password to app panel', function () {
        $response = test()->get('/forgot-password');

        $response->assertRedirect(url()->getAppUrl('forgot-password'));
    });

    it('redirects /dashboard to app panel', function () {
        $response = test()->get('/dashboard');

        $expectedUrl = rtrim(url()->getAppUrl(), '/');
        $response->assertRedirect($expectedUrl);
    });
});
