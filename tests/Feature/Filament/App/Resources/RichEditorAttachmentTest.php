<?php

declare(strict_types=1);

use App\Enums\MediaCollection;
use App\Filament\CustomFields\RichContentEntry;
use App\Filament\CustomFields\RichEditorComponent;
use App\Filament\CustomFields\RichEditorFieldType;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Providers\Filament\AppPanelProvider;
use App\Support\Media\RichContentAttachments;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Component;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(RichContentAttachments::class, RichContentEntry::class, RichEditorFieldType::class, RichEditorComponent::class, AppPanelProvider::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Storage::fake(FileUploadConfiguration::disk());
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);
    $this->body = CustomField::query()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'note')
        ->where('code', 'body')
        ->firstOrFail();
});

function noteBodyEditor(): RichEditor
{
    $page = livewire(ManageNotes::class)->mountAction('create')->instance();

    $editor = collect($page->getSchema($page->getMountedActionSchemaName())->getFlatComponents(withHidden: true))
        ->first(fn (Component $component): bool => $component instanceof RichEditor);

    expect($editor)->toBeInstanceOf(RichEditor::class);

    return $editor;
}

function livewireTemporaryUpload(string $bytes, string $name): TemporaryUploadedFile
{
    $hashed = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded(
        UploadedFile::fake()->createWithContent($name, $bytes),
    );
    Storage::disk(FileUploadConfiguration::disk())->put(FileUploadConfiguration::path($hashed, false), $bytes);

    return new TemporaryUploadedFile($hashed, FileUploadConfiguration::disk());
}

function companyBriefField(string $workspaceId): CustomField
{
    return CustomField::factory()->create([
        'tenant_id' => $workspaceId,
        'entity_type' => 'company',
        'code' => 'brief',
        'name' => 'Brief',
        'type' => 'rich-editor',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
}

it('saves a pasted image as pending media keyed by uuid', function (): void {
    $editor = noteBodyEditor();

    $id = $editor->saveUploadedFileAttachment(livewireTemporaryUpload(onePixelPng(), 'shot.png'));

    $media = Media::query()->where('uuid', $id)->firstOrFail();

    expect($media->collection_name)->toBe(MediaCollection::PendingUploads->value)
        ->and($media->name)->toBe('shot.png')
        ->and($media->workspace_id)->toBe($this->workspace->getKey())
        ->and($editor->getFileAttachmentUrl($id))->toBe($media->getUrl())
        ->and($editor->getFileAttachmentsMaxSize())->toBe(10240);
});

it('refuses a pasted file whose bytes are not an allowed image', function (): void {
    $editor = noteBodyEditor();

    expect(fn (): string => $editor->saveUploadedFileAttachment(livewireTemporaryUpload('<svg xmlns="http://www.w3.org/2000/svg"/>', 'shot.png')))
        ->toThrow(ValidationException::class);

    expect(Media::query()->count())->toBe(0);
});

it('resolves no url for an image another workspace owns or for an unknown id', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create()->personalWorkspace();
    $foreign = $stranger->addMediaFromString(onePixelPng())->usingFileName('a.png')
        ->withAttributes(['workspace_id' => $stranger->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);

    $editor = noteBodyEditor();

    expect($editor->getFileAttachmentUrl($foreign->uuid))->toBeNull()
        ->and($editor->getFileAttachmentUrl('../../.env'))->toBeNull()
        ->and($editor->getFileAttachmentUrl(''))->toBeNull()
        ->and($editor->getFileAttachmentUrl(['x']))->toBeNull();
});

it('claims the image when the note is created', function (): void {
    $id = noteBodyEditor()->saveUploadedFileAttachment(livewireTemporaryUpload(onePixelPng(), 'shot.png'));

    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'With image',
            'custom_fields' => ['body' => "<p>Shot</p><p><img data-id=\"{$id}\" src=\"stale\" alt=\"shot\"></p>"],
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'With image')->firstOrFail();
    $media = Media::query()->where('uuid', $id)->firstOrFail();

    expect($media->model_id)->toBe($note->getKey())
        ->and($media->model_type)->toBe($note->getMorphClass())
        ->and($media->collection_name)->toBe(MediaCollection::Attachments->value);
});

it('releases an image the edited body no longer references', function (): void {
    $id = noteBodyEditor()->saveUploadedFileAttachment(livewireTemporaryUpload(onePixelPng(), 'shot.png'));
    livewire(ManageNotes::class)
        ->callAction('create', ['title' => 'Edit me', 'custom_fields' => ['body' => "<p><img data-id=\"{$id}\" src=\"stale\"></p>"]])
        ->assertHasNoActionErrors();
    $note = Note::query()->where('title', 'Edit me')->firstOrFail();

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($note), ['custom_fields' => ['body' => '<p>gone</p>']])
        ->assertHasNoActionErrors();

    expect(Media::query()->where('uuid', $id)->exists())->toBeFalse();
});

it('rolls back a new note when its document belongs to another note', function (): void {
    $media = $this->workspace->addMediaFromString(pdfBytes())->usingFileName('brief.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $owner = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $body = '<p><a href="'.route('media.show', ['media' => $media->uuid]).'">Brief</a></p>';
    $owner->saveCustomFieldValue($this->body, $body);

    livewire(ManageNotes::class)
        ->callAction('create', ['title' => 'Rejected duplicate', 'custom_fields' => ['body' => $body]])
        ->assertHasErrors(['custom_fields.body'])
        ->assertNotified(__('validation.custom_field.upload', ['field' => $this->body->name]));

    expect(Note::query()->where('title', 'Rejected duplicate')->exists())->toBeFalse()
        ->and($media->refresh()->model_id)->toBe($owner->getKey());
});

it('rolls back a note title change when its document belongs to another note', function (): void {
    $media = $this->workspace->addMediaFromString(pdfBytes())->usingFileName('brief.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $owner = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $body = '<p><a href="'.route('media.show', ['media' => $media->uuid]).'">Brief</a></p>';
    $owner->saveCustomFieldValue($this->body, $body);
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Original title']);

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($note), ['title' => 'Rejected title', 'custom_fields' => ['body' => $body]])
        ->assertHasErrors(['custom_fields.body'])
        ->assertNotified(__('validation.custom_field.upload', ['field' => $this->body->name]));

    expect($note->refresh()->title)->toBe('Original title')
        ->and($media->refresh()->model_id)->toBe($owner->getKey());
});

it('rolls back a board edit when its document belongs to another record', function (): void {
    $media = $this->workspace->addMediaFromString(pdfBytes())->usingFileName('brief.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $owner = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $body = '<p><a href="'.route('media.show', ['media' => $media->uuid]).'">Brief</a></p>';
    $owner->saveCustomFieldValue($this->body, $body);
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Original task']);

    livewire(TasksBoard::class)
        ->call('mountAction', 'edit', [], ['recordKey' => (string) $task->getKey()])
        ->set('mountedActions.0.data.title', 'Rejected task')
        ->set('mountedActions.0.data.custom_fields.description', $body)
        ->call('callMountedAction')
        ->assertHasErrors(['custom_fields.description'])
        ->assertNotified();

    expect($task->refresh()->title)->toBe('Original task')
        ->and($media->refresh()->model_id)->toBe($owner->getKey());
});

it('signs document links when opening the note editor', function (): void {
    $media = $this->workspace->addMediaFromString(pdfBytes())->usingFileName('brief.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $note->saveCustomFieldValue($this->body, '<p><a href="'.route('media.show', ['media' => $media->uuid]).'">Brief</a></p>');

    livewire(ManageNotes::class)
        ->mountAction(TestAction::make('edit')->table($note))
        ->assertSchemaStateSet(function (array $state): void {
            expect(json_encode($state['custom_fields']['body']))->toContain('signature=');
        });
});

it('renders a claimed image on the record page through the provider', function (): void {
    $this->freezeTime();
    $brief = companyBriefField($this->workspace->getKey());
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $media = $this->workspace->addMediaFromString(onePixelPng())->usingFileName('a.png')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $company->saveCustomFieldValue($brief, "<p><img data-id=\"{$media->uuid}\" src=\"stale\" alt=\"brief\"></p>");

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee('/media/'.$media->uuid)
        ->assertSee(signedUrlSignature($media->refresh()->getUrl()))
        ->assertDontSee('stale');
});

it('renders a legacy public-disk image until the backfill has run', function (): void {
    Storage::disk('public')->put('legacy.png', onePixelPng());
    $brief = companyBriefField($this->workspace->getKey());
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $company->saveCustomFieldValue($brief, '<p><img data-id="legacy.png" src="https://old.test/storage/legacy.png"></p>');

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee(Storage::disk('public')->url('legacy.png'))
        ->assertDontSee('old.test');
});

it('keeps every slash-menu block when rendering a body', function (): void {
    $brief = companyBriefField($this->workspace->getKey());
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $company->saveCustomFieldValue($brief, implode('', [
        '<h2>Plan</h2>',
        '<blockquote><p>Quote</p></blockquote>',
        '<ul><li><p>Bullet</p></li></ul>',
        '<ol><li><p>Step</p></li></ol>',
        '<pre><code>code</code></pre>',
        '<table><tbody><tr><td><p>Cell</p></td></tr></tbody></table>',
        '<details><summary>Toggle</summary><div data-type="details-content"><p>Hidden</p></div></details>',
        '<hr>',
    ]));

    $page = livewire(ViewCompany::class, ['record' => $company->getKey()]);

    foreach (['<h2', '<blockquote', '<ul', '<ol', '<pre', '<table', '<details', '<summary', '<hr'] as $tag) {
        $page->assertSeeHtml($tag);
    }
});

function storedNoteBody(Note $note, CustomField $field): string
{
    return (string) CustomFieldValue::query()
        ->withoutGlobalScopes()
        ->where('entity_type', $note->getMorphClass())
        ->where('entity_id', $note->getKey())
        ->where('custom_field_id', $field->getKey())
        ->value('text_value');
}

it('stores a body image without its expiring signature', function (): void {
    $id = noteBodyEditor()->saveUploadedFileAttachment(livewireTemporaryUpload(onePixelPng(), 'shot.png'));
    $media = Media::query()->where('uuid', $id)->firstOrFail();

    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'Signed body',
            'custom_fields' => ['body' => '<p><img data-id="'.$id.'" src="'.e($media->getUrl()).'"></p>'],
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'Signed body')->firstOrFail();

    expect(storedNoteBody($note, $this->body))->toBe('<p><img data-id="'.$id.'"></p>');
});

it('does not rewrite a body image when the note is saved untouched', function (): void {
    $id = noteBodyEditor()->saveUploadedFileAttachment(livewireTemporaryUpload(onePixelPng(), 'shot.png'));
    $media = Media::query()->where('uuid', $id)->firstOrFail();

    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'Untouched',
            'custom_fields' => ['body' => '<p><img data-id="'.$id.'" src="'.e($media->getUrl()).'"></p>'],
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'Untouched')->firstOrFail();
    $before = storedNoteBody($note, $this->body);
    $activities = Activity::query()->count();

    $this->travelTo(now()->addMinutes(7));

    livewire(ManageNotes::class)
        ->mountAction(TestAction::make('edit')->table($note))
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(storedNoteBody($note, $this->body))->toBe($before)
        ->and(Activity::query()->count())->toBe($activities);
});

it('does not rewrite a body document link when the note is saved untouched', function (): void {
    $media = $this->workspace->addMediaFromString(pdfBytes())->usingFileName('brief.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $body = '<p><a href="'.route('media.show', ['media' => $media->uuid]).'">Brief</a></p>';

    livewire(ManageNotes::class)
        ->callAction('create', ['title' => 'Linked', 'custom_fields' => ['body' => $body]])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'Linked')->firstOrFail();
    $before = storedNoteBody($note, $this->body);

    $this->travelTo(now()->addMinutes(7));

    livewire(ManageNotes::class)
        ->mountAction(TestAction::make('edit')->table($note))
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($before)->not->toContain('signature=')
        ->and(storedNoteBody($note, $this->body))->toBe($before);
});

it('drops the src of an image whose id this workspace cannot resolve', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create()->personalWorkspace();
    $foreign = $stranger->addMediaFromString(onePixelPng())->usingFileName('a.png')
        ->withAttributes(['workspace_id' => $stranger->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);

    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'Foreign image',
            'custom_fields' => ['body' => '<p><img data-id="'.$foreign->uuid.'" src="'.e($foreign->getUrl()).'"></p>'],
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'Foreign image')->firstOrFail();

    expect(storedNoteBody($note, $this->body))->not->toContain('signature=')
        ->and($foreign->refresh()->model_id)->toBe($stranger->getKey());
});
