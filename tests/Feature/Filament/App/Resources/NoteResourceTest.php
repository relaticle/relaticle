<?php

declare(strict_types=1);

use App\Filament\Resources\NoteResource;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\RichEditor\SlashMenuPlugin;
use App\Models\Note;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Component;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Database\Eloquent\Model;

mutates(NoteResource::class);

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
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

    foreach (['title', 'companies.name', 'people.name', 'creator.name', 'deleted_at', 'created_at', 'updated_at'] as $column) {
        $table->assertTableColumnExists($column);
    }

    foreach (['title', 'companies.name', 'people.name', 'creator.name', 'deleted_at', 'created_at', 'updated_at'] as $column) {
        $table->assertTableColumnVisible($column);
    }

    foreach (['title', 'companies.name', 'people.name', 'creator.name', 'created_at'] as $column) {
        $table->assertCanRenderTableColumn($column);
    }

    foreach (['deleted_at', 'updated_at'] as $column) {
        $table->assertCanNotRenderTableColumn($column);
    }
});

it('can sort `:dataset` column', function (string $column): void {
    $records = Note::factory(3)->recycle([$this->user, $this->team])->create();

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
    $records = Note::factory(3)->recycle([$this->user, $this->team])->create();
    $search = data_get($records->first(), $column);

    livewire(ManageNotes::class)
        ->searchTable($search instanceof BackedEnum ? $search->value : $search)
        ->assertCanSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) === $search))
        ->assertCanNotSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) !== $search));
})->with(['title', 'creator.name']);

it('cannot display trashed records by default', function (): void {
    $records = Note::factory()->count(4)->recycle([$this->user, $this->team])->create();
    $trashedRecords = Note::factory()->trashed()->count(6)->recycle([$this->user, $this->team])->create();

    livewire(ManageNotes::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($trashedRecords)
        ->assertCountTableRecords(4);
});

it('can paginate records', function (): void {
    $records = Note::factory(20)->recycle([$this->user, $this->team])->create();

    livewire(ManageNotes::class)
        ->assertCanSeeTableRecords($records->take(10), inOrder: true)
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($records->skip(10)->take(10), inOrder: true);
});

it('can bulk delete records', function (): void {
    $records = Note::factory(5)->recycle([$this->user, $this->team])->create();

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
        'team_id' => $this->team->id,
    ]);
});

it('can edit a note', function (): void {
    $record = Note::factory()->recycle([$this->user, $this->team])->create();

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($record), data: [
            'title' => 'Updated Note',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->title)->toBe('Updated Note');
});

it('can delete a note', function (): void {
    $record = Note::factory()->recycle([$this->user, $this->team])->create();

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

it('sets creator_id and team_id via observer when creating a note', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', data: [
            'title' => 'Observer Test Note',
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'Observer Test Note')->first();

    expect($note->creator_id)->toBe($this->user->id)
        ->and($note->team_id)->toBe($this->team->id);
});

it('authorizes team member to view and update own team note', function (): void {
    $record = Note::factory()->recycle([$this->user, $this->team])->create();

    expect($this->user->can('view', $record))->toBeTrue()
        ->and($this->user->can('update', $record))->toBeTrue()
        ->and($this->user->can('delete', $record))->toBeTrue();
});

it('denies non-team-member from viewing another team note', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    $this->actingAs($otherUser);
    $record = Note::factory()->for($otherTeam)->create();
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

    expect($editor)->not->toBeNull()
        ->and($editor->getToolbarButtons())->toBe([])
        ->and(array_keys($editor->getFloatingToolbars()))->toBe(['paragraph', 'table'])
        ->and($editor->getFloatingToolbars()['paragraph'])
        ->toBe(['bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'link'])
        ->and($editor->getExtraAttributes()['class'])->toContain('fi-fo-rich-editor-seamless');

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
