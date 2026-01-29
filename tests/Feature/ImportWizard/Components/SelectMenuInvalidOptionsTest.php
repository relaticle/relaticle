<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;

it('renders invalid options with error styling in select-menu', function (): void {
    $html = View::make('import-wizard-new::components.select-menu', [
        'options' => [
            ['value' => 'active', 'label' => 'Active'],
            ['value' => 'inactive', 'label' => 'Inactive'],
        ],
        'invalidOptions' => [
            ['value' => 'invalid_status', 'label' => 'invalid_status', 'error' => 'Not a valid option'],
        ],
        'value' => 'invalid_status',
        'multiple' => false,
    ])->render();

    expect($html)
        ->toContain('invalidOptions')
        ->toContain('invalid_status');
});

it('shows invalid options at top of dropdown list', function (): void {
    $html = View::make('import-wizard-new::components.select-menu', [
        'options' => [
            ['value' => 'a', 'label' => 'Option A'],
            ['value' => 'b', 'label' => 'Option B'],
        ],
        'invalidOptions' => [
            ['value' => 'bad', 'label' => 'bad', 'error' => 'Invalid'],
        ],
        'value' => 'bad',
        'multiple' => false,
    ])->render();

    expect($html)->toContain('invalidOptions');
});

it('handles multiple invalid values in multi-select mode', function (): void {
    $html = View::make('import-wizard-new::components.select-menu', [
        'options' => [
            ['value' => 'red', 'label' => 'Red'],
            ['value' => 'blue', 'label' => 'Blue'],
        ],
        'invalidOptions' => [
            ['value' => 'purple', 'label' => 'purple', 'error' => 'Not valid'],
            ['value' => 'orange', 'label' => 'orange', 'error' => 'Not valid'],
        ],
        'value' => ['purple', 'orange', 'red'],
        'multiple' => true,
    ])->render();

    expect($html)
        ->toContain('purple')
        ->toContain('orange');
});

it('works without invalid options (backwards compatible)', function (): void {
    $html = View::make('import-wizard-new::components.select-menu', [
        'options' => [
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ],
        'value' => 'yes',
        'multiple' => false,
    ])->render();

    expect($html)
        ->toContain('Yes')
        ->toContain('No');
});
