<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;

function renderMultiValueInput(array $overrides = []): string
{
    return View::make('import-wizard-new::components.multi-value-input', array_merge([
        'value' => '',
        'placeholder' => 'Add value...',
        'disabled' => false,
        'inputType' => 'text',
        'borderless' => false,
        'errors' => [],
        'eventName' => 'input',
    ], $overrides))->render();
}

// ─── Value Parsing ───────────────────────────────────────────────────────────

it('parses comma-separated values into Alpine array', function (): void {
    $html = renderMultiValueInput([
        'value' => 'alice@test.com, bob@test.com',
    ]);

    expect($html)
        ->toContain('alice@test.com')
        ->toContain('bob@test.com');
});

it('renders single value without comma', function (): void {
    $html = renderMultiValueInput([
        'value' => 'only@one.com',
    ]);

    expect($html)->toContain('only@one.com');
});

it('filters out empty and whitespace-only values', function (): void {
    $html = renderMultiValueInput([
        'value' => 'real@test.com, , ,  , valid@test.com',
    ]);

    expect($html)
        ->toContain('real@test.com')
        ->toContain('valid@test.com');
});

it('handles null value gracefully', function (): void {
    $html = renderMultiValueInput([
        'value' => null,
    ]);

    expect($html)->toContain('data-multi-value-input');
});

it('renders empty state with placeholder', function (): void {
    $html = renderMultiValueInput([
        'placeholder' => 'Add email...',
    ]);

    expect($html)->toContain('Add email...');
});

// ─── Input Types & Link Prefixes ─────────────────────────────────────────────

it('sets mailto link prefix for email input type', function (): void {
    $html = renderMultiValueInput(['inputType' => 'email']);

    expect($html)
        ->toContain("linkPrefix: 'mailto:'")
        ->toContain('inputmode="email"');
});

it('sets tel link prefix for phone input type', function (): void {
    $html = renderMultiValueInput(['inputType' => 'tel']);

    expect($html)
        ->toContain("linkPrefix: 'tel:'")
        ->toContain('inputmode="tel"');
});

it('sets empty link prefix for url input type', function (): void {
    $html = renderMultiValueInput(['inputType' => 'url']);

    expect($html)
        ->toContain("linkPrefix: ''")
        ->toContain('inputmode="url"');
});

it('sets null link prefix for text input type', function (): void {
    $html = renderMultiValueInput(['inputType' => 'text']);

    expect($html)
        ->toContain('linkPrefix: null')
        ->toContain('inputmode="text"');
});

// ─── Component States ────────────────────────────────────────────────────────

it('renders disabled state', function (): void {
    $html = renderMultiValueInput(['disabled' => true]);

    expect($html)->toContain('isDisabled: true');
});

it('renders enabled state by default', function (): void {
    $html = renderMultiValueInput(['disabled' => false]);

    expect($html)->toContain('isDisabled: false');
});

it('renders bordered style by default', function (): void {
    $html = renderMultiValueInput(['borderless' => false]);

    expect($html)->toContain('border border-gray-200');
});

it('renders borderless style when specified', function (): void {
    $html = renderMultiValueInput(['borderless' => true]);

    expect($html)->toContain('border-0 bg-transparent');
});

it('uses custom event name for change emission', function (): void {
    $html = renderMultiValueInput(['eventName' => 'multi-value-change']);

    expect($html)->toContain("eventName: 'multi-value-change'");
});

// ─── Error Rendering ─────────────────────────────────────────────────────────

it('initializes errors from props', function (): void {
    $html = renderMultiValueInput([
        'value' => 'good@test.com, bad-value',
        'errors' => ['bad-value' => 'Invalid format'],
    ]);

    expect($html)
        ->toContain('good@test.com')
        ->toContain('bad-value')
        ->toContain('Invalid format');
});

it('initializes multiple errors from props', function (): void {
    $html = renderMultiValueInput([
        'value' => 'ok@test.com, bad1, bad2',
        'errors' => [
            'bad1' => 'Error one',
            'bad2' => 'Error two',
        ],
    ]);

    expect($html)
        ->toContain('Error one')
        ->toContain('Error two');
});

it('initializes empty errors as empty array', function (): void {
    $html = renderMultiValueInput(['errors' => []]);

    expect($html)->toContain('errors: []');
});

// ─── x-teleport & Root Element Resolution ────────────────────────────────────

it('locates root element via $refs.trigger.closest() getter', function (): void {
    $html = renderMultiValueInput();

    expect($html)
        ->toContain("this.\$refs.trigger?.closest('[data-multi-value-input]')");
});

it('dispatches events on rootEl instead of $dispatch or _rootEl', function (): void {
    $html = renderMultiValueInput();

    expect($html)
        ->toContain('this.rootEl?.dispatchEvent')
        ->not->toContain('this.$dispatch(')
        ->not->toContain('this._rootEl');
});

it('teleports panel to body', function (): void {
    $html = renderMultiValueInput();

    expect($html)->toContain('x-teleport="body"');
});

it('listens for update-errors CustomEvent on root element', function (): void {
    $html = renderMultiValueInput();

    expect($html)->toContain('x-on:update-errors="errors = $event.detail.errors || {}"');
});

// ─── DOM Ordering & Sortable ─────────────────────────────────────────────────

it('uses composite keys for x-for to prevent order mismatch with sortable', function (): void {
    $html = renderMultiValueInput([
        'value' => 'a, b, c',
    ]);

    expect($html)->toContain('`${value}-${index}`');
});

it('includes sortable directives on values list', function (): void {
    $html = renderMultiValueInput();

    expect($html)
        ->toContain('x-sortable')
        ->toContain('x-sortable-handle')
        ->toContain('reorderValues($event)');
});

// ─── Livewire Decoupling ─────────────────────────────────────────────────────

it('has no Livewire event coupling', function (): void {
    $html = renderMultiValueInput();

    expect($html)
        ->not->toContain('Livewire.on')
        ->not->toContain('$wire')
        ->not->toContain('validation-updated')
        ->not->toContain('uniqueId');
});

// ─── Accessibility ───────────────────────────────────────────────────────────

it('has proper accessibility attributes', function (): void {
    $html = renderMultiValueInput();

    expect($html)
        ->toContain('aria-haspopup="dialog"')
        ->toContain('role="dialog"')
        ->toContain('aria-label="Manage values"')
        ->toContain('aria-label="Add new value"')
        ->toContain('aria-label="Add value"');
});

// ─── Panel Structure ─────────────────────────────────────────────────────────

it('renders add input with correct placeholder', function (): void {
    $html = renderMultiValueInput(['placeholder' => 'Add tag...']);

    expect($html)->toContain('placeholder="Add tag..."');
});

it('renders delete buttons bound to deleteValue', function (): void {
    $html = renderMultiValueInput();

    expect($html)->toContain('deleteValue(value)');
});

it('renders add button bound to addValue', function (): void {
    $html = renderMultiValueInput();

    expect($html)
        ->toContain('addValue()')
        ->toContain('Add')
        ->toContain('aria-label="Add value"');
});

// ─── Lifecycle ───────────────────────────────────────────────────────────────

it('registers document click listener on init', function (): void {
    $html = renderMultiValueInput();

    expect($html)->toContain("document.addEventListener('click', this.documentClickListener)");
});

it('removes document click listener on destroy', function (): void {
    $html = renderMultiValueInput();

    expect($html)->toContain("document.removeEventListener('click', this.documentClickListener)");
});
