<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Pest\Browser\Api\AwaitableWebpage;

function companyRows(AwaitableWebpage $page, int $count): bool
{
    return $page->script("(async () => { for (let attempt = 0; attempt < 80; attempt++) { if (document.querySelectorAll('.fi-ta-row').length === {$count}) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()");
}

function addChip(AwaitableWebpage $page, string $field, string $condition): AwaitableWebpage
{
    return $page->click('[data-filter-add]')
        ->click("[data-pop-item][data-field=\"{$field}\"]")
        ->click("[data-pop]:not([style*=\"display: none\"]) [data-condition=\"{$condition}\"]");
}

function filterBarWaitFor(string $condition): string
{
    return "(async () => { for (let attempt = 0; attempt < 50; attempt++) { if ({$condition}) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()";
}

function setRules(AwaitableWebpage $page, int $count): void
{
    $page->script("Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', Array.from({ length: {$count} }, (_, index) => ({ field: 'name', condition: 'contains', value: 'chip' + index })))");
}

function valueInput(): string
{
    return '[data-pop]:not([style*="display: none"]) [data-filter-value]';
}

function optionButton(string $id): string
{
    return "[data-pop]:not([style*=\"display: none\"]) [data-option=\"{$id}\"]";
}

function searchInput(): string
{
    return '[data-pop]:not([style*="display: none"]) [data-filter-search]';
}

function chipFocus(string $selector): string
{
    return "document.activeElement === document.querySelector('{$selector}') && document.activeElement !== document.body";
}

function popVisible(): string
{
    return filterBarWaitFor('document.querySelector("[data-pop][data-open=true]")?.checkVisibility()');
}

function openOn(User $user, Workspace $workspace, string $list, int $width = 1440): AwaitableWebpage
{
    return visitAs($user, "/app/{$workspace->slug}/{$list}", $width, 900)
        ->assertPresent('[data-filter-add]');
}
