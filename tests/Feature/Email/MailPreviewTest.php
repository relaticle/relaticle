<?php

declare(strict_types=1);

use App\Http\Controllers\Dev\MailPreviewController;
use App\Support\Mail\MailPreview;

mutates(MailPreview::class, MailPreviewController::class);

it('renders every registered preview to html with the layout language attribute', function (): void {
    $preview = resolve(MailPreview::class);

    expect($preview->names())->toHaveCount(17);

    foreach ($preview->names() as $name) {
        $html = $preview->render($name);

        expect($html)->toContain('<html xmlns="http://www.w3.org/1999/xhtml" lang="en">')
            ->and($html)->not->toMatch('/\bmail\.[a-z_]+\.[a-z_]+/');
    }
});

it('does not expose the preview routes outside local', function (): void {
    expect(app('router')->has('dev.mail.index'))->toBeFalse();
});
