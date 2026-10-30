<?php

declare(strict_types=1);

use App\Http\Controllers\Dev\MailPreviewController;
use App\Support\Mail\MailPreview;

mutates(MailPreview::class, MailPreviewController::class);

it('renders every registered preview to html with the layout language attribute', function (): void {
    $preview = resolve(MailPreview::class);

    expect($preview->names())->toContain(
        'setup-nudge',
        'task-assigned',
        'task-digest',
        'workspace-invitation',
        'contact-submission',
        'workspace-deletion-scheduled',
        'workspace-deletion-reminder',
        'workspace-deletion-cancelled',
        'workspace-member-removed',
        'account-deletion-scheduled',
        'account-deletion-reminder',
        'account-deletion-cancelled',
        'verify-email',
        'verify-email-change',
        'email-change-notice',
        'reset-password',
    );

    foreach ($preview->names() as $name) {
        $html = $preview->render($name);

        expect($html)->toContain('<html xmlns="http://www.w3.org/1999/xhtml" lang="en">')
            ->and($html)->not->toMatch('/\bmail\.[a-z_]+\.[a-z_]+/');
    }
});

it('does not expose the preview routes outside local', function (): void {
    expect(app('router')->has('dev.mail.index'))->toBeFalse();
});
