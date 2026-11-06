<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->withVite();
});

function emailDemo(mixed $page, string $expression): mixed
{
    return $page->script("(() => { const root = document.querySelector('[data-email-demo]'); return {$expression}; })()");
}

it('adds the person and lands each email on the record', function (): void {
    $page = $this->visit('/email-and-calendar');
    $page->wait(4);

    expect(emailDemo($page, 'root.dataset.demoState'))->toBe('done')
        ->and(emailDemo($page, "getComputedStyle(root.querySelector('[data-mail-person]')).visibility"))->toBe('visible')
        ->and(emailDemo($page, "[...root.querySelectorAll('[data-mail-row]')].map((row) => getComputedStyle(row).visibility)"))->toBe(['visible', 'visible', 'visible'])
        ->and(emailDemo($page, "root.querySelector('[data-mail-count]').innerText"))->toBe('3');

    $page->assertNoJavaScriptErrors();
});

it('shows a teammate who and when, and the owner everything', function (): void {
    $page = $this->visit('/email-and-calendar');

    $page->click('[data-mail-seer="teammate"]');
    $page->wait(0.3);

    expect(emailDemo($page, "[...root.querySelectorAll('[data-mail-subject]')].map((subject) => subject.innerText)"))->toBe(['(subject hidden)', '(subject hidden)', '(subject hidden)'])
        ->and(emailDemo($page, "getComputedStyle(root.querySelector('[data-mail-lock]')).visibility"))->toBe('visible')
        ->and(emailDemo($page, "getComputedStyle(root.querySelector('[data-mail-preview]')).visibility"))->toBe('hidden');

    $page->click('[data-mail-seer="you"]');
    $page->wait(0.3);

    expect(emailDemo($page, "root.querySelector('[data-mail-subject]').innerText"))->toBe('Re: Renewal quote for 2027')
        ->and(emailDemo($page, "getComputedStyle(root.querySelector('[data-mail-lock]')).visibility"))->toBe('hidden')
        ->and(emailDemo($page, "root.querySelector('[data-mail-caption]').innerText"))->toBe('You always see your own email in full.');

    $page->assertNoJavaScriptErrors();
});

it('moves the choice between the two views with the arrow keys', function (): void {
    $page = $this->visit('/email-and-calendar');

    $page->click('[data-mail-seer="you"]');
    $page->keys('[data-mail-seer="you"]', 'ArrowRight');
    $page->wait(0.3);

    expect(emailDemo($page, "[...root.querySelectorAll('[data-mail-seer]')].map((radio) => radio.getAttribute('aria-checked'))"))->toBe(['false', 'true'])
        ->and(emailDemo($page, "[...root.querySelectorAll('[data-mail-seer]')].map((radio) => radio.getAttribute('tabindex'))"))->toBe(['-1', '0'])
        ->and(emailDemo($page, 'document.activeElement.dataset.mailSeer'))->toBe('teammate');

    $page->keys('[data-mail-seer="teammate"]', 'ArrowLeft');
    $page->wait(0.3);

    expect(emailDemo($page, "[...root.querySelectorAll('[data-mail-seer]')].map((radio) => radio.getAttribute('aria-checked'))"))->toBe(['true', 'false']);

    $page->assertNoJavaScriptErrors();
});
