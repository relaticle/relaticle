<?php

declare(strict_types=1);

use App\Enums\CustomFields\TaskField;
use App\Filament\CustomFields\RichEditorFieldType;
use App\Filament\RichEditor\ChecklistPlugin;
use App\Filament\RichEditor\SlashMenuPlugin;
use App\Filament\RichEditor\TaskItem;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

mutates(RichEditorFieldType::class, SlashMenuPlugin::class, ChecklistPlugin::class, TaskItem::class);

pest()->group('webkit');

it('hides the heading toolbar when the caret has no text selection', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->assertNoJavaScriptErrors();

    $state = json_decode((string) $page->script(<<<'JS'
        (() => {
            const root = document.querySelector('.fi-fo-rich-editor-seamless');
            const editorRoot = root.querySelector('[x-data^="richEditorFormComponent"]');
            const editor = Alpine.$data(editorRoot).getEditor();

            editor.commands.setContent('<h1>Quarterly review</h1>');
            editor.chain().focus().setTextSelection(4).run();

            const headingToolbar = document.querySelector('[x-ref="floatingToolbar::heading"]');
            const headingToolbarStyle = getComputedStyle(headingToolbar);

            return JSON.stringify({
                selectionEmpty: editor.view.dom.dataset.selectionEmpty ?? null,
                headingToolbarVisible:
                    headingToolbarStyle.visibility !== 'hidden' && headingToolbarStyle.opacity !== '0',
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($state)->toMatchArray([
        'selectionEmpty' => 'true',
        'headingToolbarVisible' => false,
    ]);
});

it('converts selected body text to a heading from the floating toolbar', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        (() => {
            const root = document.querySelector('.fi-fo-rich-editor-seamless');
            const editorRoot = root.querySelector('[x-data^="richEditorFormComponent"]');
            const editor = Alpine.$data(editorRoot).getEditor();

            editor.commands.setContent('<p>Quarterly review</p>');
            editor.chain().focus().setTextSelection({ from: 11, to: 17 }).run();
        })();
    JS);

    $page->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->click('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Heading 1"]')
        ->click('[x-ref="floatingToolbar::paragraph"] button[aria-label="Heading 1"]')
        ->assertScript('document.querySelector(".fi-fo-rich-editor-seamless .ProseMirror h1")?.textContent === "Quarterly review"')
        ->assertNoJavaScriptErrors();
});

it('changes a selected heading to another level from the floating toolbar', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        (() => {
            const root = document.querySelector('.fi-fo-rich-editor-seamless');
            const editorRoot = root.querySelector('[x-data^="richEditorFormComponent"]');
            const editor = Alpine.$data(editorRoot).getEditor();

            editor.commands.setContent('<h1>Quarterly review</h1>');
            editor.chain().focus().setTextSelection({ from: 1, to: 17 }).run();
        })();
    JS);

    $page->assertVisible('[x-ref="floatingToolbar::heading"] button[aria-label="Text style"]')
        ->click('[x-ref="floatingToolbar::heading"] button[aria-label="Text style"]')
        ->assertVisible('[x-ref="floatingToolbar::heading"] button[aria-label="Heading 2"]')
        ->click('[x-ref="floatingToolbar::heading"] button[aria-label="Heading 2"]')
        ->assertScript('document.querySelector(".fi-fo-rich-editor-seamless .ProseMirror h2")?.textContent === "Quarterly review"')
        ->assertNoJavaScriptErrors();
});

it('returns a selected heading to body text from the floating toolbar', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        (() => {
            const root = document.querySelector('.fi-fo-rich-editor-seamless');
            const editorRoot = root.querySelector('[x-data^="richEditorFormComponent"]');
            const editor = Alpine.$data(editorRoot).getEditor();

            editor.commands.setContent('<h2>Quarterly review</h2>');
            editor.chain().focus().setTextSelection({ from: 1, to: 17 }).run();
        })();
    JS);

    $page->assertVisible('[x-ref="floatingToolbar::heading"] button[aria-label="Text style"]')
        ->click('[x-ref="floatingToolbar::heading"] button[aria-label="Text style"]')
        ->assertVisible('[x-ref="floatingToolbar::heading"] button[aria-label="Body"]')
        ->click('[x-ref="floatingToolbar::heading"] button[aria-label="Body"]')
        ->assertScript('document.querySelector(".fi-fo-rich-editor-seamless .ProseMirror p")?.textContent === "Quarterly review"')
        ->assertNoJavaScriptErrors();
});

it('persists a heading created from selected body text', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->type('[id="mountedActionSchema0.title"]', 'Quarterly plan')
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        (() => {
            const root = document.querySelector('.fi-fo-rich-editor-seamless');
            const editorRoot = root.querySelector('[x-data^="richEditorFormComponent"]');
            const editor = Alpine.$data(editorRoot).getEditor();

            editor.commands.setContent('<p>Quarterly review</p>');
            editor.chain().focus().setTextSelection({ from: 1, to: 17 }).run();
        })();
    JS);

    $page->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->click('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->click('[x-ref="floatingToolbar::paragraph"] button[aria-label="Heading 1"]')
        ->wait(1)
        ->press('Create')
        ->assertSee('Quarterly plan')
        ->assertNoJavaScriptErrors();

    $note = Note::query()->where('title', 'Quarterly plan')->firstOrFail();
    $bodyField = CustomField::query()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'note')
        ->where('code', 'body')
        ->firstOrFail();

    expect($note->getCustomFieldValue($bodyField))->toContain('<h1>Quarterly review</h1>');
});

it('stops a detached text style dropdown from rewriting its trigger', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        (() => {
            const root = document.querySelector('.fi-fo-rich-editor-seamless');
            const editorRoot = root.querySelector('[x-data^="richEditorFormComponent"]');
            const editor = Alpine.$data(editorRoot).getEditor();

            editor.commands.setContent('<p>Quarterly review</p>');
            editor.chain().focus().setTextSelection({ from: 1, to: 17 }).run();
        })();
    JS);

    $page->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]');

    $ariaWrites = $page->script(<<<'JS'
        (async () => {
            const toolbar = document.querySelector('[x-ref="floatingToolbar::paragraph"]');
            const trigger = toolbar.querySelector('button[aria-label="Text style"]');
            const dropdown = Alpine.$data(trigger.closest('.fi-dropdown'));
            let writes = 0;

            const watcher = new MutationObserver((records) => {
                writes += records.length;

                if (writes > 100) {
                    dropdown.observer?.disconnect();
                }
            });

            Alpine.mutateDom(() => toolbar.remove());
            watcher.observe(trigger, { attributeFilter: ['aria-controls', 'aria-expanded', 'aria-haspopup'] });
            trigger.classList.add('fi-detached');

            await new Promise((resolve) => setTimeout(resolve));
            watcher.disconnect();

            return writes;
        })()
    JS);

    expect($ariaWrites)->toBe(0);
});

it('stops positioning an open text style menu once the floating toolbar re-renders', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->assertNoJavaScriptErrors();

    $selectBodyText = <<<'JS'
        (() => {
            const root = document.querySelector('.fi-fo-rich-editor-seamless');
            const editorRoot = root.querySelector('[x-data^="richEditorFormComponent"]');
            const editor = Alpine.$data(editorRoot).getEditor();

            editor.commands.setContent('<p>Introduction</p><p>Quarterly review</p>');
            editor.chain().focus().setTextSelection({ from: 15, to: 31 }).run();
        })();
    JS;

    $page->script($selectBodyText);

    $page->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->click('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Heading 1"]');

    $page->script($selectBodyText);

    $page->script(<<<'JS'
        (async () => {
            const toolbar = () => document.querySelector('[x-ref="floatingToolbar::paragraph"]');
            const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

            for (let tries = 0; tries < 40 && ! toolbar()?.isConnected; tries++) {
                await wait(50);
            }

            await wait(500);
            window.dispatchEvent(new Event('resize'));
            await wait(300);
        })()
    JS);

    $page->assertNoJavaScriptErrors()
        ->click('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Heading 1"]')
        ->assertNoJavaScriptErrors();
});

it('fits the selection toolbar inside a mobile viewport in both themes', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->resize(1440, 900)
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->assertNoJavaScriptErrors();

    $selectBodyText = <<<'JS'
        (() => {
            const root = document.querySelector('.fi-fo-rich-editor-seamless');
            const editorRoot = root.querySelector('[x-data^="richEditorFormComponent"]');
            const editor = Alpine.$data(editorRoot).getEditor();

            editor.commands.setContent('<p>Introduction</p><p>Quarterly review</p>');
            editor.chain().focus().setTextSelection({ from: 15, to: 31 }).run();
        })();
    JS;

    $page->script($selectBodyText);

    $page->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->click('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Heading 1"]');

    $page->script(<<<'JS'
        (() => {
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: 'dark' }));

            const toolbar = document.querySelector('[x-ref="floatingToolbar::paragraph"]');
            const menu = toolbar.querySelector('.fi-fo-rich-editor-dropdown-tool-menu');

            if (getComputedStyle(menu).display === 'none') {
                toolbar.querySelector('button[aria-label="Text style"]').click();
            }
        })();
    JS);

    $page->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Heading 1"]');

    $page->script(<<<'JS'
        (() => {
            const toolbar = document.querySelector('[x-ref="floatingToolbar::paragraph"]');
            const menu = toolbar.querySelector('.fi-fo-rich-editor-dropdown-tool-menu');

            if (getComputedStyle(menu).display !== 'none') {
                toolbar.querySelector('button[aria-label="Text style"]').click();
            }
        })();
    JS);

    $page->resize(390, 844);

    $page->script($selectBodyText);

    $page->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->click('[x-ref="floatingToolbar::paragraph"] button[aria-label="Text style"]')
        ->assertVisible('[x-ref="floatingToolbar::paragraph"] button[aria-label="Heading 1"]')
        ->assertNoJavaScriptErrors();

    $geometry = json_decode((string) $page->script(<<<'JS'
        (() => {
            const toolbar = document.querySelector('[x-ref="floatingToolbar::paragraph"]');
            const box = toolbar.getBoundingClientRect();
            const content = document.querySelector('.fi-fo-rich-editor-content').getBoundingClientRect();

            return JSON.stringify({
                left: box.left,
                right: box.right,
                contentLeft: content.left,
                contentRight: content.right,
                viewport: window.innerWidth,
                pageOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($geometry['left'])->toBeGreaterThanOrEqual(0)
        ->and($geometry['right'])->toBeLessThanOrEqual($geometry['viewport'])
        ->and($geometry['left'])->toBeGreaterThanOrEqual($geometry['contentLeft'])
        ->and($geometry['right'])->toBeLessThanOrEqual($geometry['contentRight'])
        ->and($geometry['pageOverflow'])->toBeFalse();
});

it('spaces paragraphs apart in the note editor', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->assertNoJavaScriptErrors();

    $gap = $page->script(<<<'JS'
        (() => {
            const root = document.querySelector('.fi-fo-rich-editor-seamless');
            const editorRoot = root.querySelector('[x-data^="richEditorFormComponent"]');
            const editor = Alpine.$data(editorRoot).getEditor();

            editor.commands.setContent('<p>Agenda</p><p>Next steps</p>');

            const [first, second] = editor.view.dom.querySelectorAll(':scope > p');

            return second.getBoundingClientRect().top - first.getBoundingClientRect().bottom;
        })();
    JS);

    expect($gap)->toBeGreaterThan(0);
});

it('opens the slash menu in the top layer, above the task modal', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/tasks")
        ->press('New task')
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->click('.fi-fo-rich-editor-seamless .ProseMirror')
        ->keys('.fi-fo-rich-editor-seamless .ProseMirror', '/')
        ->assertVisible('.fi-slash-menu');

    $menuOnTop = $page->script(<<<'JS'
        (() => {
            const menu = [...document.querySelectorAll('.fi-slash-menu')].find((panel) => panel.getBoundingClientRect().height > 0);
            const box = menu.getBoundingClientRect();

            return {
                onTop: menu.contains(document.elementFromPoint(box.left + box.width / 2, box.top + 20)),
                topLayer: menu.matches(':popover-open'),
            };
        })();
    JS);

    expect($menuOnTop)->toBe(['onTop' => true, 'topLayer' => true]);
});

it('builds, ticks and saves a checklist in a task description', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $editor = '.fi-fo-rich-editor-seamless .ProseMirror';

    $toggleBold = <<<'JS'
        (() => {
            const root = document.querySelector('.fi-fo-rich-editor-seamless [x-data^="richEditorFormComponent"]');

            Alpine.$data(root).getEditor().chain().focus().toggleBold().run();
        })();
    JS;

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/tasks")
        ->press('New task')
        ->type('[id="mountedActionSchema0.title"]', 'Prepare the offsite')
        ->assertVisible($editor)
        ->typeSlowly($editor, '/checklist', 20)
        ->keys($editor, 'Enter')
        ->typeSlowly($editor, 'Call ', 20);

    $page->script($toggleBold);

    $page->typeSlowly($editor, 'Bob', 20);

    $page->script($toggleBold);

    $page->keys($editor, 'Enter')
        ->typeSlowly($editor, 'Send quote', 20)
        ->keys($editor, ['Enter', 'Enter'])
        ->typeSlowly($editor, '[ ] Book room', 20)
        ->assertScript("document.querySelectorAll('{$editor} ul[data-type=\"taskList\"] > li').length === 3")
        ->assertScript("getComputedStyle(document.querySelector('{$editor} ul[data-type=\"taskList\"]')).listStyleType === 'none'")
        ->assertScript("getComputedStyle(document.querySelector('{$editor} ul[data-type=\"taskList\"] > li')).display === 'flex'")
        ->assertScript("(() => { const box = document.querySelector('{$editor} ul[data-type=\"taskList\"] > li > label').getBoundingClientRect(); return box.width >= 24 && box.height >= 24; })()")
        ->click("{$editor} input[aria-label=\"Checklist item: Call Bob\"]")
        ->assertScript("document.querySelectorAll('{$editor} li[data-checked=\"true\"]').length === 1")
        ->assertScript("(() => { const bold = document.querySelector('{$editor} li[data-checked=\"true\"] strong'); return getComputedStyle(bold).color === getComputedStyle(bold.closest('p')).color; })()")
        ->press('Create')
        ->assertSee('Prepare the offsite')
        ->assertNoJavaScriptErrors();

    $task = Task::query()
        ->where('title', 'Prepare the offsite')
        ->with('customFieldValues.customField.options')
        ->firstOrFail();
    $description = $task->getCustomFieldValue(
        CustomField::query()
            ->where('tenant_id', $workspace->getKey())
            ->where('entity_type', 'task')
            ->where('code', 'description')
            ->firstOrFail()
    );

    expect(substr_count((string) $description, 'data-checked="true"'))->toBe(1)
        ->and(substr_count((string) $description, 'data-checked="false"'))->toBe(2);

    $page->click('Prepare the offsite')
        ->assertVisible($editor)
        ->assertScript("document.querySelector('{$editor} input[aria-label=\"Checklist item: Call Bob\"]')?.checked === true")
        ->assertNoJavaScriptErrors();
});

it('keeps the slash placeholder off a saved plain-text description', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $task = Task::factory()->for($workspace)->create(['title' => 'Send proposal to Tim']);
    CustomFieldValue::query()->create([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => DB::table('custom_fields')->where('tenant_id', $workspace->id)->where('entity_type', 'task')->where('code', TaskField::DESCRIPTION->value)->value('id'),
        'tenant_id' => $workspace->id,
        'text_value' => 'Prepare and send the final proposal to Tim',
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/tasks")
        ->click('Send proposal to Tim')
        ->assertVisible('.fi-modal-window .ProseMirror')
        ->assertNoJavaScriptErrors();

    $page->assertSeeIn('.fi-modal-window .ProseMirror', 'Prepare and send the final proposal to Tim');

    $placeholders = $page->script("document.querySelectorAll('.fi-modal-window .fi-slash-placeholder').length");

    expect($placeholders)->toBe(0);
});

it('shows the slash placeholder in an empty editor', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->assertVisible('.fi-fo-rich-editor-seamless .ProseMirror')
        ->assertNoJavaScriptErrors();

    $page->assertVisible('.fi-fo-rich-editor-seamless .fi-slash-placeholder');
});

it('lets a slash menu item be chosen inside a modal', function (int $width, int $height): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize($width, $height)
        ->navigate("/app/{$workspace->slug}/notes")
        ->press('New note')
        ->assertVisible('.fi-modal-window .ProseMirror')
        ->click('.fi-modal-window .ProseMirror')
        ->keys('.fi-modal-window .ProseMirror', '/')
        ->assertVisible('.fi-slash-menu')
        ->click('.fi-slash-menu-item:has-text("Heading 1")')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelectorAll('.fi-modal-window .ProseMirror h1').length"))->toBe(1);
})->with([
    'phone' => [390, 844],
    'desktop' => [1440, 900],
]);
