<?php

declare(strict_types=1);

use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Models\CustomField;
use App\Models\TablePreference;
use App\Models\User;

/**
 * @return list<string>
 */
function boardStageOptionIds(string $model, string $fieldCode): array
{
    return CustomField::query()->withoutGlobalScopes()->where('entity_type', (new $model)->getMorphClass())->where('code', $fieldCode)->firstOrFail()
        ->options()->withoutGlobalScopes()->orderBy('sort_order')->pluck('id')->map(strval(...))->all();
}

/**
 * @return ?list<string>
 */
function storedStageOrder(User $user, CrmEntity $entity): ?array
{
    return TablePreference::query()->withoutGlobalScopes()->whereBelongsTo($user)->where('entity', $entity)->first()?->board_state->stageOrder;
}

function waitForStoredStageOrder(mixed $page, User $user, CrmEntity $entity, array $expected): void
{
    for ($attempt = 0; $attempt < 50 && storedStageOrder($user, $entity) !== $expected; $attempt++) {
        $page->wait(0.1);
    }
}

const BOARD_COLUMN_IDS = '(async () => { const read = () => [...document.querySelectorAll(".ff-columns > .ff-column")].map((column) => column.dataset.columnRoot).join(","); let last = read(); let stable = 0; for (let attempt = 0; attempt < 50 && stable < 3; attempt++) { await new Promise((resolve) => setTimeout(resolve, 100)); const now = read(); stable = now === last && now !== "" ? stable + 1 : 0; last = now; } return last === "" ? [] : last.split(","); })()';

const BOARD_STAGE_ROW_IDS = '[...document.querySelectorAll("[data-board-view-settings] [data-stage-id]")].map((row) => row.dataset.stageId)';

function dragWithPointerEvents(mixed $page, string $from, string $to): void
{
    $started = $page->script(sprintf(<<<'JS'
        (() => {
            if (window.boardDrag === 'running') {
                return false;
            }

            window.boardDrag = 'running';

            (async () => {
                const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

                if (! window.boardDragRequests) {
                    window.boardDragRequests = { pending: 0 };
                    window.Livewire.hook('request', ({ succeed, fail }) => {
                        const done = () => setTimeout(() => window.boardDragRequests.pending--, 300);

                        window.boardDragRequests.pending++;
                        succeed(done);
                        fail(done);
                    });
                }

                const idle = async () => {
                    for (let attempt = 0; window.boardDragRequests.pending > 0 && attempt < 100; attempt++) {
                        await wait(100);
                    }
                };

                await idle();

                const columns = document.querySelector(%1$s).closest('.ff-columns, .ff-lanes-heads');

                for (let attempt = 0; columns && ! window.Sortable.get(columns) && attempt < 50; attempt++) {
                    await wait(100);
                }

                const centre = (selector) => {
                    const box = document.querySelector(selector).getBoundingClientRect();

                    return [box.left + box.width / 2, box.top + box.height / 2];
                };
                const [fromX, fromY] = centre(%1$s);
                const [toX, toY] = centre(%2$s);
                const pointerEvents = navigator.userAgent.includes('Chrome');
                const press = (name, x, y, target = document) => {
                    const type = (pointerEvents ? 'pointer' : 'mouse') + name;
                    const init = { bubbles: true, cancelable: true, button: 0, buttons: name === 'up' ? 0 : 1, clientX: x, clientY: y };

                    target.dispatchEvent(pointerEvents ? new PointerEvent(type, { ...init, pointerId: 1, isPrimary: true, pointerType: 'mouse' }) : new MouseEvent(type, init));
                };
                const source = document.querySelector(%1$s).closest('.ff-column');
                const position = () => source?.parentElement ? [...source.parentElement.children].indexOf(source) : -1;
                const target = document.querySelector(%2$s).closest('.ff-column');
                const goal = target?.parentElement ? [...target.parentElement.children].indexOf(target) : -1;

                press('down', fromX, fromY, document.querySelector(%1$s));

                for (let step = 1; step <= 20; step++) {
                    press('move', fromX + (toX - fromX) * step / 20, fromY + (toY - fromY) * step / 20);
                    await wait(30);
                }

                for (let attempt = 0; position() !== goal && attempt < 30; attempt++) {
                    press('move', toX + (attempt %% 2 ? 2 : -2), toY);
                    await wait(100);
                }

                await wait(100);
                press('up', toX, toY);
                await wait(100);
                await idle();
            })().then(() => { window.boardDrag = 'done'; }, (error) => { window.boardDrag = 'failed: ' + error; });

            return true;
        })()
        JS, json_encode($from), json_encode($to)));

    for ($attempt = 0; $attempt < 300 && $page->script('window.boardDrag') === 'running'; $attempt++) {
        $page->wait(0.1);
    }

    expect($started)->toBeTrue()
        ->and($page->script('window.boardDrag'))->toBe('done');
}

function dragCard(mixed $page, string $from, string $to): void
{
    if (! $page->script('navigator.userAgent.includes("Chrome")')) {
        test()->markTestSkipped('Flowforge tracks a card drag through the pointer, and synthetic events never move one in WebKit.');
    }

    $page->page()->locator($from)->dragTo($page->page()->locator($to), ['steps' => 25]);
}

function boardHeaderTitle(string $id, string $container = '.ff-columns'): string
{
    return "{$container} > [data-column-root=\"{$id}\"] .ff-column-title";
}

function openBoard(User $user, string $path, int $width = 1440, string $query = ''): mixed
{
    $workspace = $user->ownedWorkspaces()->first();

    return visitAs($user, "/app/{$workspace->slug}/{$path}{$query}", $width, 900)
        ->assertVisible('.ff-column >> nth=0');
}

function boardCardCount(mixed $page, int $count): bool
{
    return $page->script("(async () => { for (let attempt = 0; attempt < 80; attempt++) { if (document.querySelectorAll('.ff-card').length === {$count}) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()");
}

function openViewSettingsPanel(mixed $page, string $trigger): void
{
    $page->click('.fi-view-settings-trigger')->assertVisible('[data-board-view-settings]')->click($trigger);
}

function closeViewSettings(mixed $page): void
{
    $page->click('.fi-topbar-page-title')
        ->assertScript(boardWaitFor('! document.querySelector("[data-board-view-settings]").checkVisibility()'), true);
}

function waitForBoardColumns(mixed $page, array $expected): void
{
    $page->assertScript(BOARD_COLUMN_IDS, $expected);
}

function storedBoardState(User $user, CrmEntity $entity): ?BoardStateData
{
    return TablePreference::query()->withoutGlobalScopes()->whereBelongsTo($user)->where('entity', $entity)->first()?->board_state;
}

function waitForPage(mixed $page, Closure $done): void
{
    for ($attempt = 0; $attempt < 50 && ! $done(); $attempt++) {
        $page->wait(0.1);
    }
}

const BOARD_ATTRIBUTE_KEYS = '[...document.querySelectorAll("[data-board-view-settings] [data-card-attribute]")].map((row) => row.dataset.cardAttribute)';

function pickRowMenuItem(mixed $page, string $trigger, string $label): void
{
    $page->click($trigger)->wait(0.5);
    $page->script('[...document.querySelectorAll(".fi-board-stages-row-menu .fi-dropdown-list-item")].find((item) => item.textContent.trim() === "'.$label.'").click()');
}

function dragSettingsRow(mixed $page, string $from, string $to): void
{
    $page->wait(0.5);
    $page->page()->locator($from)->dragTo($page->page()->locator($to), ['steps' => 10]);
}

function boardLayoutIs(string $attribute, string $value): string
{
    return "(async () => { for (let attempt = 0; attempt < 50; attempt++) { if (document.querySelector('.fi-board-header').dataset.{$attribute} === '{$value}') { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()";
}

function pressLayout(mixed $page, string $kind, string $option, string $attribute): void
{
    $page->click("[data-board-{$kind}-option=\"{$option}\"]")
        ->assertScript(boardLayoutIs($attribute, $option), true)
        ->assertScript("document.querySelector('[data-board-{$kind}-option=\"{$option}\"]').getAttribute('aria-pressed')", 'true');
}

function boardWaitFor(string $condition): string
{
    return "(async () => { for (let attempt = 0; attempt < 50; attempt++) { if ({$condition}) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()";
}
