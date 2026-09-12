<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Actions\Upload\DiscardPendingUpload;
use App\Actions\Upload\StorePendingUpload;
use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Exceptions\UploadException;
use App\Models\Team;
use App\Models\User;
use App\Support\Media\MediaPaths;
use App\Support\Media\UploadAllowlist;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractFormComponent;
use Relaticle\CustomFields\Models\CustomField;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class FileUploadComponent extends AbstractFormComponent
{
    public function create(CustomField $customField): FileUpload
    {
        return FileUpload::make($customField->getFieldName())
            ->disk(config('media-library.disk_name'))
            ->acceptedFileTypes(array_keys(UploadAllowlist::MIME_TYPES))
            ->maxSize((int) (UploadAllowlist::maxBytes() / 1024))
            ->downloadable()
            ->openable()
            ->previewable()
            ->preventFilePathTampering(allowFilePathUsing: fn (string $file, FileUpload $component): bool => $this->isAllowedPath($file, $component, $customField->code))
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file, FileUpload $component): string => $this->store($file, $component))
            ->getUploadedFileUsing(fn (string $file): ?array => $this->describe($file))
            ->deleteUploadedFileUsing(fn (string $file): null => $this->discardPending($file));
    }

    private function store(TemporaryUploadedFile $file, FileUpload $component): string
    {
        $user = auth()->user();
        $team = Filament::getTenant();

        abort_unless($user instanceof User && $team instanceof Team, 403);

        try {
            return resolve(StorePendingUpload::class)
                ->execute($user, $team, $file->getRealPath(), $file->getClientOriginalName(), UploadSource::Panel)
                ->getPathRelativeToRoot();
        } catch (UploadException $exception) {
            throw ValidationException::withMessages([$component->getStatePath() => $exception->getMessage()]);
        }
    }

    /** @return array{name: string, size: int, type: ?string, url: ?string}|null */
    private function describe(string $file): ?array
    {
        $media = $this->find($file);

        if (! $media instanceof Media) {
            return null;
        }

        return [
            'name' => $media->getCustomProperty('original_name', $media->file_name),
            'size' => (int) $media->size,
            'type' => $media->mime_type,
            'url' => $media->getUrl(),
        ];
    }

    private function discardPending(string $file): null
    {
        $user = auth()->user();
        $team = Filament::getTenant();

        if ($user instanceof User && $team instanceof Team) {
            resolve(DiscardPendingUpload::class)->execute($user, $team, $file);
        }

        return null;
    }

    // A custom-field value is not a native model attribute, so Filament's
    // getOriginalFilePaths() is always empty; every string path is authorized here.
    private function isAllowedPath(string $file, FileUpload $component, string $code): bool
    {
        $team = Filament::getTenant();

        if (! $team instanceof Team) {
            return false;
        }

        $media = resolve(MediaPaths::class)->find((string) $team->getKey(), $file);

        if ($media === null) {
            return false;
        }

        if ($media->collection_name === MediaCollection::PendingUploads->value) {
            return true;
        }

        $record = $component->getRecord();

        return $record instanceof Model
            && (string) $media->model_id === (string) $record->getKey()
            && $media->collection_name === MediaCollection::forCustomField($code);
    }

    private function find(string $file): ?Media
    {
        $team = Filament::getTenant();

        if (! $team instanceof Team) {
            return null;
        }

        return resolve(MediaPaths::class)->find((string) $team->getKey(), $file);
    }
}
