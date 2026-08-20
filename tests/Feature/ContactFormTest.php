<?php

declare(strict_types=1);

use App\Http\Controllers\ContactController;
use App\Http\Requests\ContactRequest;
use App\Mail\NewContactSubmissionMail;
use Illuminate\Support\Facades\Mail;

mutates(ContactController::class, ContactRequest::class);

it('displays the contact form', function () {
    $this->get('/contact')
        ->assertOk()
        ->assertViewIs('contact');
});

it('submits the contact form successfully', function () {
    Mail::fake();

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'company' => 'Acme Inc',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
    ])
        ->assertRedirect('/contact')
        ->assertSessionHas('success');

    Mail::assertQueued(NewContactSubmissionMail::class);
});

it('rejects an invalid submission', function () {
    Mail::fake();

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'not-an-email',
        'message' => 'Too short.',
    ])
        ->assertSessionHasErrors(['email', 'message']);

    Mail::assertNothingQueued();
});

it('silently swallows a submission that fills the honeypot field', function () {
    Mail::fake();
    config(['honeypot.enabled' => true]);

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
        'my_name' => 'I fill every field I see',
    ])
        ->assertOk();

    Mail::assertNothingQueued();
});
