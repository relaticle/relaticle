<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\SavedView;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();

    foreach (['Hot leads', 'Renewals'] as $name) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create([
            'name' => $name,
            'state' => new TableStateData(sort: 'name:desc'),
        ]);
    }
});

it('keeps a board card looking hovered while its menu is open', function (string $model, string $columnFieldCode, string $path, array $extras): void {
    TenantContextService::setTenantId($this->workspace->getKey());
    $columnField = CustomField::query()->forEntity($model)->where('code', $columnFieldCode)->firstOrFail();
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();
    $record->saveCustomFieldValue($columnField, $columnField->options->first()->getKey());
    TenantContextService::setTenantId(null);

    $card = "[data-card-id=\"{$record->getKey()}\"]";

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/{$path}", 1440, 900)
        ->assertVisible($card);

    expectHoverHeldWhileOpen($page, $card, "{$card} .ff-card-actions .fi-dropdown-trigger > button", array_map(fn (string $extra): string => "{$card} {$extra}", $extras));
    $page->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', []],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', ['.fi-board-card-preview-trigger']],
]);

it('keeps a sidebar chat looking hovered while its menu is open', function (bool $dark): void {
    DB::table('agent_conversations')->insert([
        'id' => 'cb1',
        'participant_type' => 'user',
        'participant_id' => $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'Acme onboarding',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertSee('Acme onboarding');

    $row = '.fi-sidebar-item:has([aria-label="Chat actions"])';

    expectHoverHeldWhileOpen($page, "{$row} .fi-sidebar-item-btn", '.fi-sidebar-item [aria-label="Chat actions"]', ["{$row} .fi-sidebar-item-label", "{$row} .fi-sidebar-item-icon"], $dark);
    $page->assertNoJavaScriptErrors();
})->with([false, true]);

it('keeps a list row looking hovered while its relations menu is open', function (bool $dark): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $note->people()->attach(People::factory()->count(3)->recycle([$this->user, $this->workspace])->create());

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/notes/list", 1440, 900)
        ->assertVisible('.fi-linked-records-cell-more');

    expectHoverHeldWhileOpen($page, '.fi-ta-row', '.fi-linked-records-cell-more', [], $dark);
    $page->assertNoJavaScriptErrors();
})->with([false, true]);

it('keeps a composer picker button looking hovered while its menu is open', function (bool $dark): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'email_address' => 'olivia@acme.example',
        'sync_cursor' => 'history-done',
        'last_synced_at' => now(),
    ]));

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/email", 1440, 900)
        ->click(__('filament/concerns/email-compose.actions.compose.label'))
        ->waitForText(__('filament/emails/composer.title'));

    $picker = 'button[aria-label="'.__('filament/emails/composer.actions.signature').'"]';

    expectHoverHeldWhileOpen($page, $picker, $picker, [], $dark, '[role="combobox"]');
    $page->assertNoJavaScriptErrors();
})->with([false, true]);

it('keeps pinned cells in step with a row whose popover is open', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $note->people()->attach(People::factory()->count(3)->recycle([$this->user, $this->workspace])->create());

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/notes/list", 1440, 900)
        ->assertVisible('.fi-linked-records-cell-more')
        ->click('.fi-ta-resizable-header [data-column-menu-trigger] >> nth=0')
        ->click('.fi-ta-column-menu [data-column-menu-item="pin"]')
        ->assertVisible('td[data-column-pinned="1"]');

    expectHoverHeldWhileOpen($page, '.fi-ta-row', '.fi-linked-records-cell-more', ['.fi-ta-row td[data-column-pinned="1"]', '.fi-ta-row .fi-ta-selection-cell']);
    $page->assertNoJavaScriptErrors();
});

it('leaves a collapse toggle that rests expanded looking the same as when it is collapsed', function (bool $dark): void {
    $company = Company::query()->withoutGlobalScopes()->first();

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies/{$company->getKey()}", 1440, 900);

    if ($dark) {
        $page->script('document.documentElement.classList.add("dark")');
    }

    $page->wait(1);

    $drift = $page->script(<<<'JS'
        (async () => {
            await new Promise((resolve) => setTimeout(resolve, 400));

            const toggles = [...document.querySelectorAll('[aria-expanded="true"]')]
                .filter((element) => element.getClientRects().length > 0 && ! element.closest('.fi-dropdown-trigger'));
            const look = (element) => { const style = getComputedStyle(element); return [style.backgroundColor, style.color].join(' | '); };

            const drifted = toggles.filter((element) => {
                element.style.transition = 'none';
                const expanded = look(element);
                element.setAttribute('aria-expanded', 'false');
                const collapsed = look(element);
                element.setAttribute('aria-expanded', 'true');
                element.style.transition = '';

                return expanded !== collapsed;
            }).map((element) => element.className);

            return { toggles: toggles.length > 1, drifted };
        })()
        JS);

    expect($drift)->toBe(['toggles' => true, 'drifted' => []]);
})->with([false, true]);
