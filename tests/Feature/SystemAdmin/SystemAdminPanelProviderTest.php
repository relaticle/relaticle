<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Relaticle\Chat\ChatServiceProvider;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource\Pages\ListCompanies as ListSystemAdminCompanies;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\SystemAdminPanelProvider;

mutates(SystemAdminPanelProvider::class, ChatServiceProvider::class);

it('lets administrators reorder sysadmin table columns', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    $table = Table::make(new ListSystemAdminCompanies);

    expect($table->hasReorderableColumns())->toBeTrue();
});

it('lets administrators toggle every sysadmin table column', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    $column = TextColumn::make('name');

    expect($column->isToggleable())->toBeTrue();
});

it('leaves customer table columns un-toggleable', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $column = TextColumn::make('name');

    expect($column->isToggleable())->toBeFalse();
});

it('keeps the chat assistant out of the staff panel', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    $this->get(Filament::getPanel('sysadmin')->getUrl())
        ->assertOk()
        ->assertDontSee('app.chat.chat-sidebar-nav', false)
        ->assertDontSee('chat:toggle-panel', false)
        ->assertDontSee('RECORD_CHIP_ICONS', false)
        ->assertDontSee(__('Ask :name', ['name' => config('chat.assistant_name')]));
});

it('shows the staff panel the companies of every workspace', function (): void {
    $first = User::factory()->withWorkspace()->create();
    $second = User::factory()->withWorkspace()->create();
    Company::withoutEvents(function () use ($first, $second): void {
        Company::factory()->for($first->currentWorkspace)->create(['name' => 'Northwind Traders', 'creator_id' => $first->getKey()]);
        Company::factory()->for($second->currentWorkspace)->create(['name' => 'Fabrikam Freight', 'creator_id' => $second->getKey()]);
    });
    resolve(CurrentWorkspace::class)->forget();

    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    $this->get(CompanyResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Northwind Traders')
        ->assertSee('Fabrikam Freight');
});

it('keeps showing every workspace when the staff companies table updates', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    Company::withoutEvents(fn (): Company => Company::factory()->for($owner->currentWorkspace)->create([
        'name' => 'Northwind Traders',
        'creator_id' => $owner->getKey(),
    ]));
    resolve(CurrentWorkspace::class)->forget();

    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    $page = $this->get(CompanyResource::getUrl('index'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"[^>]*wire:name="[^"]*ListCompanies"/', $page, $matches);
    app()->forgetScopedInstances();

    $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), ['components' => [[
        'snapshot' => html_entity_decode($matches[1]),
        'updates' => [],
        'calls' => [['path' => '', 'method' => 'sortTable', 'params' => ['name']]],
    ]]])
        ->assertOk()
        ->assertSee('Northwind Traders');
});
