<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use App\Http\Controllers\ContactController;
use App\Http\Requests\ContactRequest;
use App\Mail\NewContactSubmissionMail;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Spatie\Honeypot\EncryptedTime;

mutates(ContactController::class, ContactRequest::class);

it('displays the contact form', function () {
    $this->get('/contact')
        ->assertOk()
        ->assertViewIs('contact');
});

it('carries the Enterprise offer into the inquiry form', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/contact?plan=enterprise')
        ->assertSee('Let’s plan your Enterprise workspace')
        ->assertSee('From $20,000 / year')
        ->assertSee('Workflow and integrations we need:');
});

it('preserves the visitor message when an Enterprise inquiry fails validation', function (): void {
    Mail::fake();
    Feature::define(BillingFeature::class, true);

    $this->from('/contact?plan=enterprise')->post('/contact?plan=enterprise', honeypotFields() + [
        'name' => 'Maya Chen',
        'email' => 'invalid-email',
        'message' => 'Keep our Salesforce migration requirements.',
    ])->assertRedirect('/contact?plan=enterprise')->assertSessionHasErrors('email');

    $this->get('/contact?plan=enterprise')
        ->assertSee('Keep our Salesforce migration requirements.')
        ->assertDontSee('Workflow and integrations we need:');

    Mail::assertNothingQueued();
});

it('delivers the Enterprise inquiry details through the contact workflow', function (): void {
    Mail::fake();
    Feature::define(BillingFeature::class, true);
    $message = "We are interested in Relaticle Enterprise.\nIntegrate our Salesforce pipeline with our operations system.\nTeam size: 40.\nTarget timeline: November.";

    $this->post('/contact?plan=enterprise', honeypotFields() + [
        'name' => 'Maya Chen',
        'email' => 'maya@gmail.com',
        'company' => 'Northstar Operations',
        'message' => $message,
    ])->assertRedirect('/contact')->assertSessionHas('success');

    Mail::assertQueued(NewContactSubmissionMail::class, fn (NewContactSubmissionMail $mail): bool => $mail->data['message'] === $message
        && $mail->data['company'] === 'Northstar Operations');
});

it('keeps general contact inquiries free of Enterprise sales copy', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/contact?plan=other')
        ->assertDontSee('From $20,000 / year');
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
