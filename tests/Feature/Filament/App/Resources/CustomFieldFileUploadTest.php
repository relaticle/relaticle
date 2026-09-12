<?php

declare(strict_types=1);

use App\Enums\MediaCollection;
use App\Filament\CustomFields\FileColumn;
use App\Filament\CustomFields\FileEntry;
use App\Filament\CustomFields\FileUploadComponent;
use App\Filament\CustomFields\FileUploadFieldType;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(FileUploadFieldType::class, FileUploadComponent::class, FileEntry::class, FileColumn::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
    $this->contract = CustomField::factory()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'note',
        'code' => 'contract',
        'name' => 'Contract',
        'type' => 'file-upload',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
});

it('offers the file-upload type', function (): void {
    expect(CustomFieldsType::getFieldType('file-upload'))->not->toBeNull();
});

it('stores a panel upload as pending media and claims it when the note is created', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'With contract',
            'custom_fields' => ['contract' => UploadedFile::fake()->createWithContent('contract.pdf', pdfBytes())],
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'With contract')->with('customFieldValues.customField')->firstOrFail();
    $path = $note->getCustomFieldValue($this->contract);
    $media = Media::query()->where('collection_name', MediaCollection::forCustomField('contract'))->firstOrFail();

    expect($path)->toBe($media->getPathRelativeToRoot())
        ->and($media->model_id)->toBe($note->getKey())
        ->and($media->getCustomProperty('original_name'))->toBe('contract.pdf');
    Storage::disk('public')->assertExists($path);
});

it('rejects a type the allowlist refuses', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'With svg',
            'custom_fields' => ['contract' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
        ])
        ->assertHasActionErrors(['custom_fields.contract']);

    expect(Note::query()->where('title', 'With svg')->exists())->toBeFalse();
});

it('shows the stored file name as a link on the record', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $media = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->getPathRelativeToRoot());

    livewire(ManageNotes::class)
        ->assertSee('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->assertSee($media->refresh()->getUrl());
});

it('releases the file when it is removed from the form', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $media = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->getPathRelativeToRoot());

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($note), ['custom_fields' => ['contract' => null]])
        ->assertHasNoActionErrors();

    expect(Media::query()->find($media->getKey()))->toBeNull()
        ->and($note->fresh('customFieldValues.customField')->getCustomFieldValue($this->contract))->toBeNull();
});
