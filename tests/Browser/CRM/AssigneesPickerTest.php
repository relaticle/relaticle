<?php

declare(strict_types=1);

use App\Filament\Components\Forms\WorkspaceMemberSelect;
use App\Models\Task;
use App\Models\User;

mutates(WorkspaceMemberSelect::class);

it('assigns members from the search panel and keeps the closed field on one row', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create(['name' => 'Olivia Owner']);
    $workspace = $user->ownedWorkspaces()->first();
    $members = User::factory()
        ->count(5)
        ->sequence(fn ($sequence): array => ['name' => "Maximilian Montgomery {$sequence->index}"])
        ->create();
    $workspace->users()->attach($members, ['role' => 'member']);
    $task = Task::factory()->recycle([$user, $workspace])->create(['title' => 'Send the proposal']);

    $picker = '.fi-linked-records:has([id$="assignees"])';
    $search = "{$picker} .fi-linked-records-search input";
    $options = "[...document.querySelectorAll('{$picker} [role=option]')].map((option) => option.innerText.replace(/\\s+/g, ' ').trim()).join('|')";
    $chips = "[...document.querySelectorAll('{$picker} .fi-linked-records-chips > a')].map((chip) => chip.innerText.trim()).join('|')";
    $editTask = "/app/{$workspace->slug}/tasks?tableAction=edit&tableActionRecord={$task->getKey()}";

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate($editTask)
        ->waitForText('Assignees')
        ->assertScript("document.querySelector('{$picker} .fi-linked-records-placeholder').textContent.trim()", 'Assign a member')
        ->click("{$picker} .fi-linked-records-field")
        ->assertScript("document.activeElement === document.querySelector('{$search}')", true)
        ->assertScript("{$options}.split('|')[0].endsWith('Olivia Owner (You)')", true)
        ->keys($search, 'ArrowDown')
        ->keys($search, 'Enter');

    foreach ([5, 4, 3, 2, 1] as $remaining) {
        $page->type($search, 'montgomery')
            ->assertScript("document.querySelector('{$picker} [role=option].fi-active') !== null", true)
            ->assertScript("{$options}.split('|').filter(Boolean).length", $remaining)
            ->keys($search, 'Enter');
    }

    $page->assertScript("{$chips}.split('|').length", 6)
        ->keys($search, 'Escape')
        ->click('Save changes')
        ->assertScript("document.querySelector('{$picker}') === null", true)
        ->assertNoJavaScriptErrors();

    expect($task->assignees()->count())->toBe(6);

    $layout = <<<JS
        (() => {
            const chips = [...document.querySelectorAll('{$picker} .fi-linked-records-chips > a')];
            const shown = chips.filter((chip) => getComputedStyle(chip).visibility !== 'hidden');

            return {
                total: chips.length,
                shown: shown.length,
                counter: document.querySelector('{$picker} .fi-linked-records-count').textContent,
                oneRow: new Set(shown.map((chip) => Math.round(chip.getBoundingClientRect().top))).size === 1,
                matchesInputs: document.querySelector('{$picker} .fi-linked-records-field').offsetHeight === document.querySelector('.fi-modal input[id$="title"]').offsetHeight,
            };
        })();
    JS;

    $page->navigate($editTask)
        ->waitForText('Assignees')
        ->assertScript("document.querySelector('{$picker} .fi-linked-records-count').textContent.startsWith('+')", true);

    $closed = $page->script($layout);

    expect($closed)->toMatchArray(['total' => 6, 'oneRow' => true, 'matchesInputs' => true])
        ->and($closed['shown'])->toBeGreaterThan(0)->toBeLessThan(6)
        ->and($closed['counter'])->toBe('+'.(6 - $closed['shown']));

    $page->click("{$picker} .fi-linked-records-chips > a:first-of-type")
        ->assertScript("document.querySelector('{$picker} .fi-linked-records-panel')._x_isShown", true)
        ->assertNoJavaScriptErrors();
});
