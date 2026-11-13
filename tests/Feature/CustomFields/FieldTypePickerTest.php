<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

it('offers the field types as the dropdown they were before 4.0', function (): void {
    livewire('manage-fields-table', ['entityType' => 'company'])
        ->mountAction('createField')
        ->assertFormFieldExists('type', fn (Select $field): bool => str_contains($field->toHtml(), 'fi-fo-select-wrp'));
});

it('keeps the attribute table on the 4.0 presentation', function (): void {
    livewire('manage-fields-table', ['entityType' => 'company'])
        ->assertSeeHtml('data-flavor="polished" data-surface="attribute-table"');
});
