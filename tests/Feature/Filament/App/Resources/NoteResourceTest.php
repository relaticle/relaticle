<?php

declare(strict_types=1);

use App\Filament\Components\Forms\LinkedRecordsSelect;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\NoteResource\Pages\NotesCards;
use App\Filament\RichEditor\SlashMenuPlugin;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\ToolbarButtonGroup;
use Filament\Schemas\Components\Component;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Database\Eloquent\Model;

mutates(NoteResource::class, NotesCards::class, LinkedRecordsSelect::class);

beforeEach(function () {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('can render the index page', function (): void {
    livewire(ManageNotes::class)
        ->assertOk();
});

// Column metadata is checked against a single mounted table rather than one
// dataset case per column: mounting the page dominates the cost, and Filament's
// assertion messages already name the offending column.
it('exposes the expected table columns', function (): void {
    $table = livewire(ManageNotes::class);

    foreach (['title', 'relations', 'creator.name', 'deleted_at', 'created_at', 'updated_at'] as $column) {
        $table->assertTableColumnExists($column);
    }

    foreach (['title', 'relations', 'creator.name', 'deleted_at', 'created_at', 'updated_at'] as $column) {
        $table->assertTableColumnVisible($column);
    }

    foreach (['title', 'relations', 'creator.name', 'created_at'] as $column) {
        $table->assertCanRenderTableColumn($column);
    }

    foreach (['deleted_at', 'updated_at'] as $column) {
        $table->assertCanNotRenderTableColumn($column);
    }
});

it('can sort `:dataset` column', function (string $column): void {
    $records = Note::factory(3)->recycle([$this->user, $this->workspace])->create();

    $sortingKey = data_get($records->first(), $column) instanceof BackedEnum
        ? fn (Model $record) => data_get($record, $column)->value
        : $column;

    livewire(ManageNotes::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($sortingKey), inOrder: true)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($sortingKey), inOrder: true);
})->with(['creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('can search `:dataset` column', function (string $column): void {
    $records = Note::factory(3)->recycle([$this->user, $this->workspace])->create();
    $search = data_get($records->first(), $column);

    livewire(ManageNotes::class)
        ->searchTable($search instanceof BackedEnum ? $search->value : $search)
        ->assertCanSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) === $search))
        ->assertCanNotSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) !== $search));
})->with(['title', 'creator.name']);

it('cannot display trashed records by default', function (): void {
    $records = Note::factory()->count(4)->recycle([$this->user, $this->workspace])->create();
    $trashedRecords = Note::factory()->trashed()->count(6)->recycle([$this->user, $this->workspace])->create();

    livewire(ManageNotes::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($trashedRecords)
        ->assertCountTableRecords(4);
});

it('can paginate records', function (): void {
    $records = Note::factory(30)->recycle([$this->user, $this->workspace])->create();

    livewire(ManageNotes::class)
        ->assertCanSeeTableRecords($records->take(25), inOrder: true)
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($records->skip(25), inOrder: true);
});

it('can bulk delete records', function (): void {
    $records = Note::factory(5)->recycle([$this->user, $this->workspace])->create();

    livewire(ManageNotes::class)
        ->assertCanSeeTableRecords($records)
        ->selectTableRecords($records)
        // NOTE: Using direct action array instead of TestAction::make()->bulk()
        // because TestAction triggers unnecessary form building during bulk actions
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertNotified()
        ->assertCanNotSeeTableRecords($records);

    $this->assertSoftDeleted($records);
});

it('can create a note', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', data: [
            'title' => 'New Note',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas(Note::class, [
        'title' => 'New Note',
        'workspace_id' => $this->workspace->id,
    ]);
});

it('can edit a note', function (): void {
    $record = Note::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($record), data: [
            'title' => 'Updated Note',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->title)->toBe('Updated Note');
});

it('links companies, people and opportunities to a note from the relations picker', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ManageNotes::class)
        ->callAction('create', data: [
            'title' => 'Renewal call',
            'relations' => ["company:{$company->id}", "people:{$person->id}", "opportunity:{$opportunity->id}"],
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'Renewal call')->sole();

    expect($note->companies->modelKeys())->toBe([$company->id])
        ->and($note->people->modelKeys())->toBe([$person->id])
        ->and($note->opportunities->modelKeys())->toBe([$opportunity->id]);
});

it('fills the relations picker from the linked records and unlinks the ones removed', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $kept = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $removed = People::factory()->recycle([$this->user, $this->workspace])->create();
    $note->companies()->attach($kept);
    $note->people()->attach($removed);

    livewire(ManageNotes::class)
        ->mountAction(TestAction::make('edit')->table($note))
        ->assertSchemaStateSet(['relations' => ["company:{$kept->id}", "people:{$removed->id}"]])
        ->fillForm(['relations' => ["company:{$kept->id}"]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($note->companies()->pluck('companies.id')->all())->toBe([$kept->id])
        ->and($note->people()->count())->toBe(0);
});

it('rejects a record from another workspace in the relations picker', function (): void {
    $otherUser = User::factory()->withWorkspace()->create();
    $foreign = Company::factory()->recycle([$otherUser, $otherUser->currentWorkspace])->create();
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($note), data: ['relations' => ["company:{$foreign->id}"]])
        ->assertHasActionErrors(['relations.0']);

    expect($note->companies()->count())->toBe(0);
});

it('can delete a note', function (): void {
    $record = Note::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('delete')->table($record));

    $this->assertSoftDeleted($record);
});

it('validates title is required on create', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', data: [
            'title' => null,
        ])
        ->assertHasActionErrors(['title' => 'required']);
});

it('has `:dataset` filter', function (string $filter): void {
    livewire(ManageNotes::class)
        ->assertTableFilterExists($filter);
})->with(['creation_source', 'trashed']);

it('sets creator_id and workspace_id via observer when creating a note', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', data: [
            'title' => 'Observer Test Note',
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'Observer Test Note')->first();

    expect($note->creator_id)->toBe($this->user->id)
        ->and($note->workspace_id)->toBe($this->workspace->id);
});

it('authorizes workspace member to view and update own workspace note', function (): void {
    $record = Note::factory()->recycle([$this->user, $this->workspace])->create();

    expect($this->user->can('view', $record))->toBeTrue()
        ->and($this->user->can('update', $record))->toBeTrue()
        ->and($this->user->can('delete', $record))->toBeTrue();
});

it('denies non-workspace-member from viewing another workspace note', function (): void {
    $otherUser = User::factory()->withWorkspace()->create();
    $otherWorkspace = $otherUser->currentWorkspace;

    $this->actingAs($otherUser);
    $record = Note::factory()->for($otherWorkspace)->create();
    $this->actingAs($this->user);

    expect($this->user->can('view', $record))->toBeFalse()
        ->and($this->user->can('update', $record))->toBeFalse()
        ->and($this->user->can('delete', $record))->toBeFalse();
});

it('accepts deeply nested rich-editor JSON in custom field action data', function (): void {
    // The Filament RichEditor JS-side state is the TipTap document JSON,
    // entangled to $wire.mountedActions.0.data.custom_fields.<field>. As the user
    // edits nested lists/blockquotes, Livewire diffs the document and pushes
    // partial updates with deeply nested dot paths. The default Livewire 4
    // payload.max_nesting_depth = 10 is insufficient: the prefix
    // (mountedActions.0.data.custom_fields.<field>) already consumes 5 levels,
    // leaving only 5 for TipTap content, which is easily exceeded.
    $deepPath = 'mountedActions.0.data.custom_fields.body.content.1.content.1.content.2.content.0.content';

    livewire(ManageNotes::class)
        ->set($deepPath, [['type' => 'text', 'text' => 'hello']]);
})->throwsNoExceptions();

it('drives note body formatting from the slash menu rather than a toolbar', function (): void {
    $page = livewire(ManageNotes::class)
        ->mountAction('create')
        ->instance();

    $schema = $page->getSchema($page->getMountedActionSchemaName());

    $editor = collect($schema->getFlatComponents(withHidden: true))
        ->first(fn (Component $component): bool => $component instanceof RichEditor);

    $paragraphToolbar = $editor->getFloatingToolbars()['paragraph'];
    $headingToolbar = $editor->getFloatingToolbars()['heading'];

    expect($editor)->not->toBeNull()
        ->and($editor->getToolbarButtons())->toBe([])
        ->and(array_keys($editor->getFloatingToolbars()))->toBe(['paragraph', 'heading', 'table'])
        ->and($editor->getExtraAttributes()['class'])->toContain('fi-fo-rich-editor-seamless');

    foreach ([$paragraphToolbar, $headingToolbar] as $toolbar) {
        expect($toolbar[0])->toBeInstanceOf(ToolbarButtonGroup::class)
            ->and($toolbar[0]->getName())->toBe('Text style')
            ->and($toolbar[0]->getButtons())->toBe(['paragraph', 'h1', 'h2', 'h3'])
            ->and($toolbar[0]->hasTextualButtons())->toBeTrue()
            ->and(collect($toolbar[0]->getResolvedButtons())->map->getLabel()->all())
            ->toBe(['Body', 'Heading 1', 'Heading 2', 'Heading 3'])
            ->and(array_slice($toolbar, 1))
            ->toBe(['bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'link']);
    }

    expect($paragraphToolbar[0])->not->toBe($headingToolbar[0]);

    foreach (['attachFiles', 'link'] as $name) {
        expect($editor->getActions()[$name]->shouldOverlayParentActions())->toBeTrue();
    }

    $menu = json_decode(
        base64_decode(SlashMenuPlugin::attributes($editor)['data-slash-menu']),
        associative: true,
    );

    $items = $menu['items'];

    expect($menu['noResults'])->toContain('"')
        ->and($menu['placeholder'])->toContain(':key')
        ->and(collect($items)->pluck('label')->all())
        ->toBe(['Heading 1', 'Heading 2', 'Heading 3', 'Body', 'Quote', 'Bulleted list', 'Numbered list', 'Code', 'Table', 'Toggle', 'Divider', 'Image'])
        ->and(collect($items)->pluck('group')->unique()->values()->all())
        ->toBe(['Text', 'Lists', 'Insert']);

    expect(collect($items)->pluck('shortcut', 'id')->filter()->all())
        ->toBe([
            'h1' => '#',
            'h2' => '##',
            'h3' => '###',
            'blockquote' => '>',
            'bulletList' => '-',
            'orderedList' => '1.',
            'horizontalRule' => '---',
        ]);

    expect(collect($items)->pluck('action')->filter()->all())->toHaveSameSize($items)
        ->and(collect($items)->pluck('icon')->filter()->all())->toHaveSameSize($items);
});

it('versions the slash menu script by its published file so an edit changes the url', function (): void {
    $published = filemtime(public_path('js/app/rich-editor-slash-menu.js'));

    expect(FilamentAsset::getScriptSrc('rich-editor-slash-menu'))->toEndWith("?v={$published}");
});

it('keeps file attachments enabled on a toolbarless note body', function (): void {
    $page = livewire(ManageNotes::class)
        ->mountAction('create')
        ->instance();

    $editor = collect($page->getSchema($page->getMountedActionSchemaName())->getFlatComponents(withHidden: true))
        ->first(fn (Component $component): bool => $component instanceof RichEditor);

    expect($editor->hasFileAttachments())->toBeTrue();
});

it('groups note cards by when they were created and counts each group', function (): void {
    $this->travelTo('2026-10-07 12:00:00');

    $note = fn (string $title, string $createdAt): Note => Note::factory()
        ->recycle([$this->user, $this->workspace])
        ->create(['title' => $title, 'created_at' => $createdAt]);

    $note('Written this morning', '2026-10-07 08:00:00');
    $note('Written on Monday', '2026-10-05 09:00:00');
    $note('Written on Tuesday', '2026-10-06 09:00:00');
    $note('Written in March', '2026-03-25 09:00:00');
    $note('Written last year', '2025-06-01 09:00:00');

    livewire(NotesCards::class)
        ->assertOk()
        ->assertSeeHtmlInOrder([
            'Created today <span class="fi-ta-group-count">1</span>',
            'Written this morning',
            'Created this week <span class="fi-ta-group-count">2</span>',
            'Written on Tuesday',
            'Written on Monday',
            'Created this year <span class="fi-ta-group-count">1</span>',
            'Written in March',
            'Created earlier <span class="fi-ta-group-count">1</span>',
            'Written last year',
        ])
        ->assertDontSee('Created this month');
});

it('shows the note body on a card as plain text', function (): void {
    Note::factory()->recycle([$this->user, $this->workspace])->create([
        'title' => 'Call recap',
        'custom_fields' => ['body' => '<p>Agreed on <strong>pricing</strong>.</p><p>Follow up Friday.</p>'],
    ]);
    Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Empty note']);

    livewire(NotesCards::class)
        ->assertSee('Agreed on pricing. Follow up Friday.')
        ->assertSee('This note has no content.')
        ->assertSee($this->user->name);
});

it('searches note cards by title and recounts the group', function (): void {
    $match = Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Renewal terms']);
    $other = Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Kickoff agenda']);

    livewire(NotesCards::class)
        ->assertSeeHtml('Created today <span class="fi-ta-group-count">2</span>')
        ->searchTable('Renewal')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other])
        ->assertSeeHtml('Created today <span class="fi-ta-group-count">1</span>');
});

it('edits a note from its card', function (): void {
    $record = Note::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(NotesCards::class)
        ->callAction(TestAction::make('edit')->table($record), data: ['title' => 'Edited from a card'])
        ->assertHasNoActionErrors();

    expect($record->refresh()->title)->toBe('Edited from a card');
});

it('opens notes on the cards view and links it to the list', function (string $page): void {
    livewire($page)
        ->assertSeeHtml('href="'.NoteResource::getUrl('index').'"')
        ->assertSeeHtml('href="'.NoteResource::getUrl('list').'"');
})->with([ManageNotes::class, NotesCards::class]);

it('serves the cards view at the notes index and the table at the list route', function (): void {
    $this->get(NoteResource::getUrl('index'))->assertSeeLivewire(NotesCards::class);
    $this->get(NoteResource::getUrl('list'))->assertSeeLivewire(ManageNotes::class);
});

it('shows the first linked record on a card and counts the rest', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $note->people()->attach(People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Priya Raman']));
    $note->companies()->attach(Company::factory()->count(2)->recycle([$this->user, $this->workspace])->create());

    livewire(NotesCards::class)
        ->assertSee('Priya Raman')
        ->assertSeeHtml('<span class="fi-note-card-more">+2</span>');
});

it('marks a deleted note on its card', function (): void {
    Note::factory()->recycle([$this->user, $this->workspace])->create();
    $trashed = Note::factory()->trashed()->recycle([$this->user, $this->workspace])->create();

    livewire(NotesCards::class)
        ->assertDontSeeHtml('fi-note-card-deleted-badge')
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$trashed])
        ->assertSeeHtml('fi-note-card-deleted-badge');
});

it('shows 24 note cards on a page', function (): void {
    $records = Note::factory(25)->recycle([$this->user, $this->workspace])->create();

    livewire(NotesCards::class)
        ->assertCanSeeTableRecords($records->take(24))
        ->assertCanNotSeeTableRecords($records->skip(24));
});

it('offers a per page choice on note cards only once there is more than one page', function (): void {
    Note::factory(24)->recycle([$this->user, $this->workspace])->create();

    livewire(NotesCards::class)
        ->assertDontSeeHtml('fi-pagination-records-per-page-select');

    Note::factory()->recycle([$this->user, $this->workspace])->create();

    $component = livewire(NotesCards::class)
        ->assertSeeHtml('fi-pagination-records-per-page-select');

    expect($component->instance()->getTable()->getPaginationPageOptions())->toBe([24, 48, 96]);
});

it('points the navigation link at the view the user opened last', function (): void {
    livewire(ManageNotes::class)->assertOk();

    expect(NoteResource::getNavigationUrl())->toBe(NoteResource::getUrl('list'));

    livewire(NotesCards::class)->assertOk();

    expect(NoteResource::getNavigationUrl())->toBe(NoteResource::getUrl('index'));
});
