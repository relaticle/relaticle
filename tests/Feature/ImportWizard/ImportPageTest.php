<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\ImportWizard\Filament\Pages\ImportCompanies;
use Relaticle\ImportWizard\Filament\Pages\ImportNotes;
use Relaticle\ImportWizard\Filament\Pages\ImportOpportunities;
use Relaticle\ImportWizard\Filament\Pages\ImportPage;
use Relaticle\ImportWizard\Filament\Pages\ImportPeople;
use Relaticle\ImportWizard\Filament\Pages\ImportTasks;
use Symfony\Component\DomCrawler\Crawler;

mutates(ImportPage::class, CompanyResource::class, PeopleResource::class, OpportunityResource::class, TaskResource::class, NoteResource::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

dataset('import pages', [
    'companies' => [ImportCompanies::class, 'Companies'],
    'people' => [ImportPeople::class, 'People'],
    'opportunities' => [ImportOpportunities::class, 'Opportunities'],
    'tasks' => [ImportTasks::class, 'Tasks'],
    'notes' => [ImportNotes::class, 'Notes'],
]);

it('keeps the :dataset sidebar item active while importing', function (string $page, string $label): void {
    $html = $this->get($page::getUrl())->assertOk()->getContent();

    $active = new Crawler($html)
        ->filter('.fi-sidebar-item.fi-active')
        ->each(fn (Crawler $item): string => trim($item->text()));

    expect($active)->toBe([$label]);
})->with('import pages');

it('titles the :dataset import page under a link back to its list', function (string $page, string $label): void {
    $html = $this->get($page::getUrl())->assertOk()->getContent();

    $heading = new Crawler($html)->filter('[data-page-heading]');
    $trail = $heading->filter('.fi-record-breadcrumb-link');

    expect($trail->attr('href'))->toBe($page::getResourceClass()::getUrl('index'))
        ->and(trim($trail->text()))->toBe($label)
        ->and(trim($heading->filter('.fi-topbar-page-title')->text()))->toBe('Import');
})->with('import pages');
