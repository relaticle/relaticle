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
