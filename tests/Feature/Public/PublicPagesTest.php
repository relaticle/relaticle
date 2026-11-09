<?php

declare(strict_types=1);

use App\Providers\MacroServiceProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;

mutates(MacroServiceProvider::class);

it('redirects the root to the app panel', function (): void {
    $this->get('/')->assertRedirect(url()->getAppUrl());
})->skip(fn (): bool => class_exists('Relaticle\Cloud\CloudServiceProvider'), 'The cloud package serves the homepage.');

describe('Authentication redirects', function () {
    it('redirects login to app panel', function () {
        $response = $this->get('/login');

        $response->assertRedirect(url()->getAppUrl('login'));
    });

    it('redirects register to app panel', function () {
        $response = $this->get('/register');

        $response->assertRedirect(url()->getAppUrl('login'));
    });

    it('passes the referring page across the :path redirect to the app panel', function (string $path): void {
        $this->get($path)->assertHeader('Referrer-Policy', 'no-referrer-when-downgrade');
    })->with(['/login', '/register']);

    it('redirects forgot password to app panel', function () {
        $response = $this->get('/forgot-password');

        $response->assertRedirect(Filament::getPanel('app')->getRequestPasswordResetUrl());
    });

    it('redirects dashboard to app panel', function () {
        $response = $this->get('/dashboard');

        $response->assertRedirect(url()->getAppUrl());
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

        $response->assertRedirect(route('login'));
    });

    it('rejects github as a provider for redirect', function () {
        $response = $this->get('/auth/redirect/github');

        $response->assertNotFound();
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

describe('security.txt', function () {
    it('serves the security contact with a future expiry', function () {
        $response = $this->get('/.well-known/security.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Contact: mailto:security@relaticle.com', false);
        $response->assertSee('Canonical:', false);

        preg_match('/Expires: (.+)/', (string) $response->getContent(), $matches);
        expect(Date::parse($matches[1])->isFuture())->toBeTrue();
    });
});
