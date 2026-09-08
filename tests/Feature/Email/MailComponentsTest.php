<?php

declare(strict_types=1);

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\View;

final class ComponentProbeMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Probe');
    }

    public function content(): Content
    {
        return new Content(markdown: 'probe::components', with: [
            'rows' => [
                ['label' => 'Call client', 'url' => 'https://app.relaticle.test/tasks/1', 'meta' => 'Due Sep 8, 2026'],
                ['label' => 'Email', 'value' => 'ana@example.com'],
            ],
        ]);
    }
}

final class BareProbeMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bare');
    }

    public function content(): Content
    {
        return new Content(markdown: 'probe::components-bare');
    }
}

beforeEach(function (): void {
    config(['relaticle.company.name' => 'Relaticle Inc', 'relaticle.company.address' => '1 Market St, San Francisco']);

    View::addNamespace('probe', __DIR__.'/../../fixtures/mail-views');
});

it('renders a hidden preheader in html only', function (): void {
    $mail = new ComponentProbeMail;

    $mail->assertSeeInHtml('Preview line for the inbox');
    $mail->assertDontSeeInText('Preview line for the inbox');

    expect($mail->render())->toMatch('/<div class="preheader"[^>]*>Preview line for the inbox/');
});

it('renders list rows with links, values, and meta in html and text', function (): void {
    $mail = new ComponentProbeMail;

    $mail->assertSeeInHtml('<a href="https://app.relaticle.test/tasks/1"', escape: false);
    $mail->assertSeeInHtml('Due Sep 8, 2026');
    $mail->assertSeeInHtml('ana@example.com');
    $mail->assertSeeInText('Call client: https://app.relaticle.test/tasks/1 (Due Sep 8, 2026)');
    $mail->assertSeeInText('Email: ana@example.com');
    $mail->assertSeeInHtml('>Email</strong>', escape: false);
});

it('renders company identity, the reason line, and both footer links', function (): void {
    $mail = new ComponentProbeMail;

    $mail->assertSeeInHtml('Relaticle Inc');
    $mail->assertSeeInHtml('1 Market St, San Francisco');
    $mail->assertSeeInHtml('You received this because you enabled the daily digest.');
    $mail->assertSeeInHtml('href="https://app.relaticle.test/settings"', escape: false);
    $mail->assertSeeInHtml('href="https://relaticle.test/unsub"', escape: false);
    $mail->assertSeeInText(__('mail.footer.unsubscribe').': https://relaticle.test/unsub');
});

it('omits the address and links when they are not provided', function (): void {
    config(['relaticle.company.address' => '']);

    $mail = new BareProbeMail;

    $mail->assertDontSeeInHtml('1 Market St');
    $mail->assertDontSeeInHtml(__('mail.footer.unsubscribe'));
    $mail->assertDontSeeInHtml(__('mail.footer.settings'));
    $mail->assertSeeInHtml('Relaticle Inc');
});
