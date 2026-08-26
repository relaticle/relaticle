<?php

declare(strict_types=1);

use App\Http\Controllers\ContactController;
use App\Http\Requests\ContactRequest;
use App\Mail\NewContactSubmissionMail;
use Illuminate\Support\Facades\Mail;
use Spatie\Honeypot\EncryptedTime;

mutates(ContactController::class, ContactRequest::class);

it('displays the contact form', function () {
    $this->get('/contact')
        ->assertOk()
        ->assertViewIs('contact');
});

it('submits the contact form successfully', function () {
    Mail::fake();
    config(['honeypot.enabled' => true]);

    $this->post('/contact', honeypotFields() + [
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

it('rejects a submission that carries no honeypot fields at all', function () {
    Mail::fake();
    config(['honeypot.enabled' => true]);

    // The shape a scripted POST actually takes: it never rendered the form, so it
    // sends none of the hidden fields. With honeypot_fields_required_for_all_forms
    // left at the package default this check is skipped outright and only a bot
    // polite enough to fill the hidden input is ever caught.
    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
    ])->assertOk();

    Mail::assertNothingQueued();
});

it('rejects a submission sent faster than a human could have filled the form', function () {
    Mail::fake();
    config(['honeypot.enabled' => true]);

    $this->post('/contact', [
        'my_name' => '',
        'valid_from' => (string) EncryptedTime::create(now()->addMinute()),
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
    ])->assertOk();

    Mail::assertNothingQueued();
});

/**
 * What a real browser submits: an empty honeypot input plus the encrypted
 * timestamp the form was rendered with, far enough in the past to clear the
 * minimum-fill-time check.
 *
 * @return array<string, string>
 */
function honeypotFields(): array
{
    return [
        'my_name' => '',
        'valid_from' => (string) EncryptedTime::create(now()->subMinute()),
    ];
}
