<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;

it('renders options with invalid flag in select-menu', function (): void {
    $html = View::make('import-wizard-new::components.select-menu', [
        'options' => [
            ['value' => 'invalid_status', 'label' => 'invalid_status', 'invalid' => true],
            ['value' => 'active', 'label' => 'Active'],
            ['value' => 'inactive', 'label' => 'Inactive'],
        ],
        'value' => 'invalid_status',
        'multiple' => false,
    ])->render();

    expect($html)
        ->toContain('invalid_status')
        ->toContain('Active');
});

it('renders invalid options with badge styling', function (): void {
    $html = View::make('import-wizard-new::components.select-menu', [
        'options' => [
            ['value' => 'bad', 'label' => 'bad', 'invalid' => true],
            ['value' => 'a', 'label' => 'Option A'],
            ['value' => 'b', 'label' => 'Option B'],
        ],
        'value' => 'bad',
        'multiple' => false,
    ])->render();

    expect($html)
        ->toContain('bad')
        ->toContain('Option A');
});

it('handles multiple values with some invalid in multi-select mode', function (): void {
    $html = View::make('import-wizard-new::components.select-menu', [
        'options' => [
            ['value' => 'purple', 'label' => 'purple', 'invalid' => true],
            ['value' => 'orange', 'label' => 'orange', 'invalid' => true],
            ['value' => 'red', 'label' => 'Red'],
            ['value' => 'blue', 'label' => 'Blue'],
        ],
        'value' => ['purple', 'orange', 'red'],
        'multiple' => true,
    ])->render();

    expect($html)
        ->toContain('purple')
        ->toContain('orange')
        ->toContain('Red');
});

it('works with only valid options', function (): void {
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
