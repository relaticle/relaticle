<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

describe('Home page', function () {
    it('returns a successful response', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Relaticle');
    });

    it('displays the GitHub stars count', function () {
        Http::fake([
            'api.github.com/repos/Relaticle/relaticle' => Http::response([
                'stargazers_count' => 125,
            ], 200),
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('125');
    });
});

describe('Legal pages', function () {
    it('displays the terms of service page', function () {
        $response = $this->get('/terms-of-service');

        $response->assertStatus(200);
        $response->assertSee('Terms of Service');
    });

    it('displays the privacy policy page', function () {
        $response = $this->get('/privacy-policy');

        $response->assertStatus(200);
        $response->assertSee('Privacy Policy');
    });
});

describe('Documentation pages', function () {
    it('displays the documentation index', function () {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertSee('Documentation');
    });

    it('returns 404 for non-existent documentation page', function () {
        $response = $this->get('/docs/non-existent-page');

        $response->assertStatus(404);
    });

    it('can search documentation', function () {
        $response = $this->get('/docs/search?query=test');

        $response->assertStatus(200);
    });
});

describe('Authentication redirects', function () {
    it('redirects login to app panel', function () {
        $response = $this->get('/login');

        $response->assertRedirect(url()->getAppUrl('login'));
    });

    it('redirects register to app panel', function () {
        $response = $this->get('/register');

        $response->assertRedirect(url()->getAppUrl('register'));
    });

    it('redirects forgot password to app panel', function () {
        $response = $this->get('/forgot-password');

        $response->assertRedirect(url()->getAppUrl('forgot-password'));
    });

    it('redirects dashboard to app panel', function () {
        $response = $this->get('/dashboard');

        $response->assertRedirect(url()->getAppUrl());
    });
});

describe('Community redirects', function () {
    it('redirects to discord', function () {
        config(['services.discord.invite_url' => 'https://discord.gg/example']);

        $response = $this->get('/discord');

        $response->assertRedirect('https://discord.gg/example');
    });
});

describe('Social authentication routes', function () {
    it('throttles authentication redirect attempts', function () {
        // Make 10 requests (the limit)
        for ($i = 0; $i < 10; $i++) {
            $this->get('/auth/redirect/github');
        }

        // The 11th request should be throttled
        $response = $this->get('/auth/redirect/github');

        $response->assertStatus(429); // Too Many Requests
    });

    it('accepts github as a provider for redirect', function () {
        $response = $this->get('/auth/redirect/github');

        $response->assertStatus(302); // Redirect to GitHub
    });

    it('accepts google as a provider for redirect', function () {
        $response = $this->get('/auth/redirect/google');

        $response->assertStatus(302); // Redirect to Google
    });
});

describe('Error handling', function () {
    it('returns 404 for non-existent routes', function () {
        $response = $this->get('/non-existent-page');

        $response->assertStatus(404);
    });
});

describe('Response meta', function () {
    it('returns proper content type', function () {
        $response = $this->get('/');

        $response->assertHeader('Content-Type');
        $response->assertSuccessful();
    });
});
