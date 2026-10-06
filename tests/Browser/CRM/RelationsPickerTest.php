<?php

declare(strict_types=1);

use App\Filament\Components\Forms\LinkedRecordsSelect;
use App\Models\Company;
use App\Models\Note;
use App\Models\People;
use App\Models\User;

mutates(LinkedRecordsSelect::class);

it('links, unlinks and saves records from the keyboard without leaving the search box', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Northwind Traders']);
    People::factory()->recycle([$user, $workspace])->create(['name' => 'Nora Hale', 'company_id' => $company->id]);
    $note = Note::factory()->recycle([$user, $workspace])->create(['title' => 'Kickoff call']);

    $search = '.fi-linked-records-search input';
    $options = "[...document.querySelectorAll('.fi-linked-records [role=option]')].map((option) => option.innerText.replace(/\\s+/g, ' ').trim()).join('|')";
    $chips = "[...document.querySelectorAll('.fi-linked-records-chips > a')].map((chip) => chip.innerText.trim()).join('|')";

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/notes?tableAction=edit&tableActionRecord={$note->getKey()}")
        ->waitForText('Relations')
        ->click('.fi-linked-records-trigger')
        ->assertScript("document.activeElement === document.querySelector('{$search}')", true)
        ->assertScript($options, 'Northwind Traders|Nora Hale Northwind Traders')
        ->type($search, 'north')
        ->assertScript($options, 'Northwind Traders')
        ->keys($search, 'Enter')
        ->assertScript($chips, 'Northwind Traders')
        ->assertScript("document.querySelector('{$search}').value", '')
        ->assertScript($options, 'Nora Hale Northwind Traders')
        ->keys($search, 'ArrowDown')
        ->keys($search, 'Enter')
        ->assertScript($chips, 'Northwind Traders|Nora Hale')
        ->assertScript("document.querySelector('.fi-linked-records-message').textContent", 'Search to link another record.')
        ->keys($search, 'Backspace')
        ->assertScript($chips, 'Northwind Traders')
        ->keys($search, 'Escape')
        ->assertScript("document.querySelector('.fi-linked-records-trigger').getAttribute('aria-expanded')", 'false')
        ->assertScript("document.activeElement === document.querySelector('.fi-linked-records-trigger')", true)
        ->assertSee('Edit Kickoff call')
        ->click('Save changes')
        ->assertScript("document.querySelector('.fi-linked-records') === null", true)
        ->assertNoJavaScriptErrors();

    expect($note->companies()->pluck('companies.id')->all())->toBe([$company->id])
        ->and($note->people()->count())->toBe(0);

    $page->navigate("/app/{$workspace->slug}/notes?tableAction=edit&tableActionRecord={$note->getKey()}")
        ->waitForText('Relations')
        ->assertScript($chips, 'Northwind Traders');
});

it('keeps the panel open and the search box focused while records are linked and unlinked with the mouse', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Company::factory()->recycle([$user, $workspace])->create(['name' => 'Northwind Traders', 'updated_at' => now()]);
    Company::factory()->recycle([$user, $workspace])->create(['name' => 'Harbor Freight', 'updated_at' => now()->subDay()]);
    $note = Note::factory()->recycle([$user, $workspace])->create(['title' => 'Kickoff call']);

    $search = '.fi-linked-records-search input';
    $stillOpen = "document.querySelector('.fi-linked-records-panel')._x_isShown && document.activeElement === document.querySelector('{$search}')";
    $chips = "[...document.querySelectorAll('.fi-linked-records-selected .fi-linked-records-chip')].map((chip) => chip.innerText.trim()).join('|')";

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/notes?tableAction=edit&tableActionRecord={$note->getKey()}")
        ->waitForText('Relations')
        ->click('.fi-linked-records-field')
        ->assertScript("document.querySelectorAll('.fi-linked-records [role=option]').length", 2)
        ->click('[role=option]:has-text("Northwind Traders")')
        ->assertScript($chips, 'Northwind Traders')
        ->assertScript($stillOpen, true)
        ->click('[role=option]:has-text("Harbor Freight")')
        ->assertScript($chips, 'Northwind Traders|Harbor Freight')
        ->assertScript($stillOpen, true)
        ->click('[aria-label="Remove Northwind Traders"]')
        ->assertScript($chips, 'Harbor Freight')
        ->assertScript($stillOpen, true)
        ->click('.fi-linked-records-group-label')
        ->assertScript($stillOpen, true)
        ->keys($search, 'Tab')
        ->assertScript("document.querySelector('.fi-linked-records-panel')._x_isShown", false)
        ->assertScript("document.querySelector('.fi-linked-records').contains(document.activeElement)", false)
        ->click('.fi-linked-records-field')
        ->assertScript($stillOpen, true)
        ->click('.fi-linked-records-field')
        ->assertScript("document.querySelector('.fi-linked-records-panel')._x_isShown", false)
        ->assertNoJavaScriptErrors();
});

it('keeps the closed field on one row, counts what does not fit and links each chip to its record', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $note = Note::factory()->recycle([$user, $workspace])->create(['title' => 'Quarterly review']);
    $companies = Company::factory()
        ->count(14)
        ->recycle([$user, $workspace])
        ->sequence(fn ($sequence): array => ['name' => "Consolidated Holdings Group {$sequence->index}"])
        ->create();
    $note->companies()->attach($companies);

    $layout = <<<'JS'
        (() => {
            const field = document.querySelector('.fi-linked-records-field');
            const chips = [...document.querySelectorAll('.fi-linked-records-chips > a')];
            const shown = chips.filter((chip) => getComputedStyle(chip).visibility !== 'hidden');
            const row = document.querySelector('.fi-linked-records-chips').getBoundingClientRect();

            return {
                matchesInputs: field.offsetHeight === document.querySelector('.fi-modal input[id$="title"]').offsetHeight,
                total: chips.length,
                shown: shown.length,
                counter: document.querySelector('.fi-linked-records-count').textContent,
                oneRow: new Set(shown.map((chip) => Math.round(chip.getBoundingClientRect().top))).size === 1,
                insideField: shown.every((chip) => chip.getBoundingClientRect().right <= row.right + 1 && chip.getBoundingClientRect().width > 40),
                opensRecord: shown.every((chip) => /\/companies\/[0-9a-z]{26}$/.test(chip.href) && chip.target === '_blank'),
            };
        })();
    JS;

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/notes?tableAction=edit&tableActionRecord={$note->getKey()}")
        ->waitForText('Relations')
        ->assertScript("document.querySelector('.fi-linked-records-count').textContent.startsWith('+')", true)
        ->assertNoJavaScriptErrors();

    $desktop = $page->script($layout);

    expect($desktop)->toMatchArray(['matchesInputs' => true, 'total' => 14, 'oneRow' => true, 'insideField' => true, 'opensRecord' => true])
        ->and($desktop['shown'])->toBeGreaterThan(1)->toBeLessThan(14)
        ->and($desktop['counter'])->toBe('+'.(14 - $desktop['shown']));

    $page->resize(390, 844)
        ->assertScript("document.querySelector('.fi-linked-records-count').textContent !== '{$desktop['counter']}'", true);

    $phone = $page->script($layout);

    expect($phone)->toMatchArray(['matchesInputs' => true, 'oneRow' => true, 'insideField' => true])
        ->and($phone['shown'])->toBeLessThan($desktop['shown'])
        ->and($phone['counter'])->toBe('+'.(14 - $phone['shown']));
});
