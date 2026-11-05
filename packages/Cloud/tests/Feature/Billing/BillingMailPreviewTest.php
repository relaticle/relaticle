<?php

declare(strict_types=1);

use App\Support\Mail\MailPreview;
use Relaticle\Cloud\CloudServiceProvider;

mutates(CloudServiceProvider::class, MailPreview::class);

it('registers the billing mail previews and renders each one', function (): void {
    $preview = resolve(MailPreview::class);

    expect($preview->names())->toContain('trial-ending', 'trial-ended', 'extension-ended', 'subscription-ended');

    foreach (['trial-ending', 'trial-ended', 'extension-ended', 'subscription-ended'] as $name) {
        expect($preview->render($name))
            ->toContain('<html xmlns="http://www.w3.org/1999/xhtml" lang="en">')
            ->not->toMatch('/\bmail\.[a-z_]+\.[a-z_]+/');
    }

    expect($preview->render('trial-ended'))->toContain('Extend your trial by 7 days');
    expect($preview->render('extension-ended'))->toContain('Your extended trial for Acme Robotics has ended')->not->toContain('Extend your trial');
});
