<?php

declare(strict_types=1);

use App\Actions\Upload\DiscardPendingUpload;
use App\Enums\MediaCollection;
use App\Filament\CustomFields\FileColumn;
use App\Filament\CustomFields\FileEntry;
use App\Filament\CustomFields\FileUploadComponent;
use App\Filament\CustomFields\FileUploadFieldType;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Component;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(FileUploadFieldType::class, FileUploadComponent::class, FileEntry::class, FileColumn::class, DiscardPendingUpload::class);

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

it('rejects an svg through the accepted file types', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'With svg',
            'custom_fields' => ['contract' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
        ])
        ->assertHasActionErrors(['custom_fields.contract']);

    expect(Note::query()->where('title', 'With svg')->exists())->toBeFalse();
});

it('shows the original file name, not the storage name, as a link on the record', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $media = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey(), 'original_name' => 'Contract v2.pdf'])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->getPathRelativeToRoot());

    livewire(ManageNotes::class)
        ->assertSee('Contract v2.pdf')
        ->assertSee($media->refresh()->getUrl());
});

it('shows the original file name through a real infolist entry', function (): void {
    $companyField = CustomField::factory()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'company',
        'code' => 'attachment',
        'name' => 'Attachment',
        'type' => 'file-upload',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $media = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey(), 'original_name' => 'Master Agreement.pdf'])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $company->saveCustomFieldValue($companyField, $media->getPathRelativeToRoot());

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertOk()
        ->assertSee('Master Agreement.pdf')
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

it('resolves the original file name and url through getUploadedFileUsing', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $media = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey(), 'original_name' => 'Signed Contract.pdf'])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->getPathRelativeToRoot());

    $test = livewire(ManageNotes::class)->mountAction(TestAction::make('edit')->table($note));

    $schemaName = $test->instance()->getMountedActionSchemaName();
    $schema = $test->instance()->{$schemaName};
    $component = $schema->getComponent(fn (Component|Action|ActionGroup $component): bool => $component instanceof FileUpload
        && $component->getName() === $this->contract->getFieldName());

    $files = $test->instance()->callSchemaComponentMethod($component->getKey(), 'getUploadedFiles');

    $file = array_values($files)[0];

    expect($file['name'])->toBe('Signed Contract.pdf')
        ->and($file['url'])->toBe($media->refresh()->getUrl());
});

it('deletes a pending upload but leaves a claimed one alone', function (): void {
    $pending = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('pending.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);

    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $claimed = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('claimed.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $claimed->getPathRelativeToRoot());
    $claimedPath = $claimed->refresh()->getPathRelativeToRoot();

    resolve(DiscardPendingUpload::class)->execute($this->user, $this->team, $pending->getPathRelativeToRoot());
    resolve(DiscardPendingUpload::class)->execute($this->user, $this->team, $claimedPath);

    expect(Media::query()->find($pending->getKey()))->toBeNull()
        ->and(Media::query()->find($claimed->getKey()))->not->toBeNull();
});

it('rejects a pasted path claimed by another record on the same field', function (): void {
    $noteA = Note::factory()->recycle([$this->user, $this->team])->create();
    $noteB = Note::factory()->recycle([$this->user, $this->team])->create();
    $media = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $noteA->saveCustomFieldValue($this->contract, $media->getPathRelativeToRoot());
    $claimedPath = $media->refresh()->getPathRelativeToRoot();

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($noteB), ['custom_fields' => ['contract' => [(string) Str::uuid() => $claimedPath]]])
        ->assertHasActionErrors(['custom_fields.contract']);
});

it('allows re-saving a record with its own currently claimed path', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $media = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->getPathRelativeToRoot());
    $claimedPath = $media->refresh()->getPathRelativeToRoot();

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($note), ['custom_fields' => ['contract' => [(string) Str::uuid() => $claimedPath]]])
        ->assertHasNoActionErrors();
});
