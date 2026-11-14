<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Pest\Browser\Api\AwaitableWebpage;
use Tests\Helpers\WorkspaceCustomField;

function dialogCompany(Workspace $workspace, User $owner, string $name, array $domains = []): Company
{
    $company = Company::factory()->recycle([$owner, $workspace])->create(['name' => $name]);

    if ($domains !== []) {
        $company->saveCustomFieldValue(WorkspaceCustomField::byCode($workspace->getKey(), 'company', 'domains'), $domains);
    }

    return $company;
}

function dialogPerson(Workspace $workspace, User $owner, string $name, array $emails = []): People
{
    $person = People::factory()->recycle([$owner, $workspace])->create(['name' => $name]);

    if ($emails !== []) {
        $person->saveCustomFieldValue(WorkspaceCustomField::byCode($workspace->getKey(), 'people', 'emails'), $emails);
    }

    return $person;
}

function openMergeDialog(User $user, Workspace $workspace, Company|People $record, int $width = 1440, int $height = 900): AwaitableWebpage
{
    $segment = $record instanceof People ? 'people' : 'companies';

    return visitAs($user, "/app/{$workspace->slug}/{$segment}/{$record->getKey()}", $width, $height)
        ->click('.fi-record-rail-actions .fi-dropdown-trigger button')
        ->click('.fi-dropdown-list-item:has-text("Merge record")')
        ->assertVisible('[data-merge-dialog]');
}

function pickInMergeDialog(AwaitableWebpage $page, string $search, string $name): AwaitableWebpage
{
    return $page->click('[data-merge-picker]')
        ->type('[data-merge-popover] input', $search)
        ->wait(1.2)
        ->click("[data-merge-popover] li:has-text(\"{$name}\")")
        ->wait(1.2);
}

const MERGE_SUBMIT_DISABLED = 'document.querySelector(".fi-modal-footer-actions button[type=submit]").getAttribute("aria-disabled") === "true"';

const MERGE_LAYOUT = <<<'JS'
    (() => {
        const rect = (selector) => document.querySelector(selector).getBoundingClientRect();
        const left = rect('[data-merge-left]');
        const right = rect('[data-merge-right]');
        const result = rect('[data-merge-result]');
        const swap = rect('[data-merge-swap]');
        const swapX = swap.left + swap.width / 2;
        const swapY = swap.top + swap.height / 2;
        const tolerance = 1.5;
        const intersects = (a, b) => a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
        const border = (selector) => parseFloat(getComputedStyle(document.querySelector(selector)).borderTopWidth);

        return {
            sameRow: Math.abs(left.top - right.top) < tolerance && Math.abs(left.top - result.top) < tolerance,
            sameHeight: Math.abs(left.height - right.height) < tolerance && Math.abs(left.height - result.height) < tolerance,
            sameWidth: Math.abs(left.width - right.width) < tolerance && Math.abs(left.width - result.width) < tolerance,
            resultAfterSources: result.left >= right.right,
            swapClear: ! intersects(swap, left) && ! intersects(swap, right),
            sourcesBordered: border('[data-merge-left] > div') > 0 && border('[data-merge-right] > div') > 0,
            swapBetweenSlots: swapX > left.right && swapX < right.left,
            swapStacked: swapY > left.bottom && swapY < right.top,
            resultBelow: result.top >= right.bottom,
            overflows: document.documentElement.scrollWidth > document.documentElement.clientWidth
                || document.querySelector('.fi-modal-window').scrollWidth > document.querySelector('.fi-modal-window').clientWidth,
        };
    })();
JS;

const MERGE_POPOVER_RECT = <<<'JS'
    (() => {
        const box = document.querySelector('[data-merge-popover]').getBoundingClientRect();
        const anchor = document.querySelector('[data-merge-picker]').getBoundingClientRect();
        const modal = document.querySelector('.fi-modal-window').getBoundingClientRect();

        return {
            left: box.left,
            top: box.top,
            bottom: box.bottom,
            right: box.right,
            height: box.height,
            above: box.bottom <= anchor.top,
            anchorLeft: anchor.left,
            modalLeft: modal.left,
            modalRight: modal.right,
            windowWidth: window.innerWidth,
            windowHeight: window.innerHeight,
            rows: document.querySelectorAll('[data-merge-popover] li').length,
        };
    })()
JS;

function searchMergePicker(AwaitableWebpage $page, string $term): array
{
    $page->script(<<<JS
        (() => {
            const input = document.querySelector('[data-merge-popover] input');
            input.value = '{$term}';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        })()
        JS);

    return $page->wait(1.2)->script(MERGE_POPOVER_RECT);
}
