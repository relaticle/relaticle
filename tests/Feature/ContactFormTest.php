<?php

declare(strict_types=1);

use App\Http\Controllers\ContactController;
use App\Http\Requests\ContactRequest;
use App\Mail\NewContactSubmissionMail;
use App\Rules\TurnstileChallenge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;

mutates(ContactController::class, ContactRequest::class, TurnstileChallenge::class);

function configureTurnstile(): void
{
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
}

it('displays the contact form', function () {
    $this->get('/contact')
        ->assertOk()
        ->assertViewIs('contact');
});

it('renders the turnstile widget only once it is fully configured', function () {
    $this->get('/contact')
        ->assertOk()
        ->assertDontSee('cf-turnstile', escape: false)
        ->assertDontSee('challenges.cloudflare.com', escape: false);

    configureTurnstile();

    $this->get('/contact')
        ->assertOk()
        ->assertSee('cf-turnstile', escape: false)
        ->assertSee('challenges.cloudflare.com', escape: false);
});

it('submits the contact form successfully with valid turnstile', function () {
    Mail::fake();
    configureTurnstile();
    Turnstile::fake();

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'company' => 'Acme Inc',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
        'cf-turnstile-response' => Turnstile::dummy(),
    ])
        ->assertRedirect('/contact')
        ->assertSessionHas('success');

    Mail::assertQueued(NewContactSubmissionMail::class);
});

it('rejects submission when turnstile verification fails', function () {
    Mail::fake();
    configureTurnstile();
    Turnstile::fake()->fail();

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
        'cf-turnstile-response' => 'invalid-token',
    ])
        ->assertSessionHasErrors('cf-turnstile-response');

    Mail::assertNothingQueued();
});

it('rejects submission when turnstile response is missing', function () {
    Mail::fake();
    configureTurnstile();

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
    ])
        ->assertSessionHasErrors('cf-turnstile-response');

    Mail::assertNothingQueued();
});

it('accepts a submission on a half-configured install instead of erroring', function (?string $key, ?string $secret) {
    Mail::fake();
    config([
        'services.turnstile.key' => $key,
        'services.turnstile.secret' => $secret,
    ]);

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'company' => 'Acme Inc',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
    ])
        ->assertRedirect('/contact')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    Mail::assertQueued(NewContactSubmissionMail::class);
})->with([
    'site key without a secret' => ['test-site-key', null],
    'secret without a site key' => [null, 'test-secret-key'],
    'neither configured' => [null, null],
]);

it('degrades to a readable error when cloudflare siteverify is unreachable', function () {
    Mail::fake();
    configureTurnstile();
    Http::fake(['challenges.cloudflare.com/*' => Http::response('', 500)]);

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
        'cf-turnstile-response' => 'a-perfectly-good-token',
    ])
        ->assertRedirect()
        ->assertSessionHasErrors(['cf-turnstile-response' => __('auth.turnstile.unavailable')]);

    Mail::assertNothingQueued();
});

it('rejects a siteverify verdict that is not explicitly successful', function () {
    Mail::fake();
    configureTurnstile();
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => []])]);

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
        'cf-turnstile-response' => 'a-token-cloudflare-refused',
    ])
        ->assertRedirect()
        ->assertSessionHasErrors(['cf-turnstile-response' => __('auth.turnstile.failed')]);

    Mail::assertNothingQueued();
});
