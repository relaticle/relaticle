<?php

declare(strict_types=1);

describe('getAppUrl macro', function () {
    it('returns path-based URL by default', function () {
        config(['app.app_panel_domain' => null]);
        config(['app.app_panel_path' => 'app']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('login'))->toBe('https://example.com/app/login');
        expect(url()->getAppUrl())->toBe('https://example.com/app/');
    });

    it('returns domain-based URL when APP_PANEL_DOMAIN is set', function () {
        config(['app.app_panel_domain' => 'app.example.com']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('login'))->toBe('https://app.example.com/login');
        expect(url()->getAppUrl())->toBe('https://app.example.com/');
    });

    it('handles custom path', function () {
        config(['app.app_panel_domain' => null]);
        config(['app.app_panel_path' => 'crm']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('login'))->toBe('https://example.com/crm/login');
    });

    it('handles port in APP_URL', function () {
        config(['app.app_panel_domain' => null]);
        config(['app.app_panel_path' => 'app']);
        config(['app.url' => 'http://localhost:8080']);

        expect(url()->getAppUrl('login'))->toBe('http://localhost:8080/app/login');
    });

    it('domain mode uses scheme from APP_URL', function () {
        config(['app.app_panel_domain' => 'crm.example.com']);
        config(['app.url' => 'https://example.com']);

        expect(url()->getAppUrl('login'))->toBe('https://crm.example.com/login');
    });
});

describe('getPublicUrl macro', function () {
    it('returns APP_URL based URL', function () {
        config(['app.url' => 'https://example.com']);

        expect(url()->getPublicUrl('about'))->toBe('https://example.com/about');
    });
});
