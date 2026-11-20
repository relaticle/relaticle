<?php

declare(strict_types=1);

use App\Features\Billing;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    $this->withVite();

    Feature::for(null)->activate(Billing::class);
});

it('reprices the per-seat bar as the team and seat price change', function (): void {
    $page = $this->visit('/pricing')->resize(1440, 900);

    $page->script(<<<'JS'
        (() => {
            const set = (selector, value) => {
                const input = document.querySelector(selector);
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            };
            set('#team-cost-people', '10');
            set('#team-cost-seat', '30');
        })()
    JS);

    $read = <<<'JS'
        ({
            people: document.querySelector('[data-team-cost-people]').textContent.trim(),
            amounts: [...document.querySelectorAll('[data-team-cost-amount]')].map((amount) => amount.textContent.trim()),
            verdict: document.querySelector('[data-team-cost-verdict]').textContent.trim(),
            insets: ['seat', 'pro', 'scale'].map((key) => Math.round(parseFloat(document.querySelector(`[data-team-cost-bar="${key}"]`).style.clipPath.split(' ')[1]))),
        })
    JS;

    expect($page->wait(0.5)->script($read))->toBe([
        'people' => '10',
        'amounts' => ['$300', '$19', '$49'],
        'verdict' => 'A per-seat CRM costs $3,372 more a year than Cloud Pro.',
        'insets' => [0, 94, 84],
    ]);

    $page->assertNoJavaScriptErrors();
});

it('names the break-even team size when a per-seat crm costs less', function (): void {
    $page = $this->visit('/pricing')->resize(390, 844);

    $page->script(<<<'JS'
        (() => {
            const set = (selector, value) => {
                const input = document.querySelector(selector);
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            };
            set('#team-cost-people', '1');
            set('#team-cost-seat', '9');
        })()
    JS);

    expect($page->wait(0.5)->script("document.querySelector('[data-team-cost-verdict]').textContent.trim()"))
        ->toBe('A per-seat CRM costs less at this size. Cloud Pro costs less from 3 people.')
        ->and($page->script('document.documentElement.scrollWidth'))->toBe(390);
});
