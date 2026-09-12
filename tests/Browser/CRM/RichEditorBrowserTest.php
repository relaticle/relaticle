<?php

declare(strict_types=1);

use App\Filament\CustomFields\RichEditorFieldType;
use App\Filament\RichEditor\SlashMenuPlugin;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\User;

mutates(RichEditorFieldType::class, SlashMenuPlugin::class);

it('hides the heading toolbar when the caret has no text selection', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/notes")
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
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/notes")
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
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/notes")
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
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/notes")
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
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/notes")
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
        ->press('Create')
        ->assertSee('Quarterly plan')
        ->assertNoJavaScriptErrors();

    $note = Note::query()->where('title', 'Quarterly plan')->firstOrFail();
    $bodyField = CustomField::query()
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'note')
        ->where('code', 'body')
        ->firstOrFail();

    expect($note->getCustomFieldValue($bodyField))->toContain('<h1>Quarterly review</h1>');
});

it('fits the selection toolbar inside a mobile viewport in both themes', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/notes")
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
