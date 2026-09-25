<?php

declare(strict_types=1);

use App\Mail\TaskAssignedMail;

it('declares the document language and marks every layout table presentational', function (): void {
    $html = (new TaskAssignedMail('Call client', 'https://app.relaticle.test/tasks/1'))->render();

    preg_match_all('/<table\b[^>]*>/', $html, $tables);

    expect($html)->toContain('<html xmlns="http://www.w3.org/1999/xhtml" lang="en">')
        ->and($tables[0])->not->toBeEmpty()
        ->and($tables[0])->each->toContain('role="presentation"')
        ->and($html)->toContain('<a href="https://app.relaticle.test/tasks/1"');
});

it('ships a light logo by default and a white logo that both dark rule sets reveal', function (): void {
    $html = (new TaskAssignedMail('Call client', 'https://app.relaticle.test/tasks/1'))->render();

    preg_match('/@media \(prefers-color-scheme: dark\)\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/s', $html, $dark);

    expect($html)->toContain('brand/email-logo.png')
        ->and($html)->toContain('brand/email-logo-dark.png')
        ->and($html)->toMatch('/<img[^>]*class="logo logo-light"[^>]*alt="Relaticle"/')
        ->and($html)->toMatch('/<div class="logo-dark-wrap"[^>]*display: none/')
        ->and($dark[1])->toMatch('/\.logo-light\s*\{\s*display: none !important/')
        ->and($dark[1])->toMatch('/\.logo-dark-wrap\s*\{\s*display: block !important/')
        ->and($html)->toMatch('/\[data-ogsc\] \.logo-light\s*\{\s*display: none !important/')
        ->and($html)->toMatch('/\[data-ogsc\] \.logo-dark-wrap\s*\{\s*display: block !important/');
});
