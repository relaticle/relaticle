<?php

declare(strict_types=1);

use App\Http\Controllers\ContactController;
use App\Http\Requests\ContactRequest;
use App\Mail\NewContactSubmissionMail;
use Illuminate\Support\Facades\Mail;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;

mutates(ContactController::class, ContactRequest::class);

it('displays the contact form', function () {
    $this->get('/contact')
        ->assertOk()
        ->assertViewIs('contact');
});

it('submits the contact form successfully with valid turnstile', function () {
    Mail::fake();
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

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
    ])
        ->assertSessionHasErrors('cf-turnstile-response');

    Mail::assertNothingQueued();
});
