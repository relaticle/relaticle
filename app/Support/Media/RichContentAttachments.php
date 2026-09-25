<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Actions\Upload\StorePendingUpload;
use App\Enums\UploadSource;
use App\Exceptions\UploadException;
use App\Models\User;
use App\Models\Workspace;
use Dom\HTMLDocument;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class RichContentAttachments implements FileAttachmentProvider
{
    // Filament's default flow stored the bare public-disk filename as data-id. Those bodies
    // keep rendering until media:backfill-rich-editor-attachments has rewritten them.
    private const string LEGACY_FILENAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    private function __construct(private string $workspaceId, private MediaLookup $lookup) {}

    public static function forWorkspace(string $workspaceId): self
    {
        return new self($workspaceId, resolve(MediaLookup::class));
    }

    public function attribute(RichContentAttribute $attribute): static
    {
        return $this;
    }

    public function getFileAttachmentUrl(mixed $file): ?string
    {
        if (! is_string($file) || $file === '') {
            return null;
        }

        if (Str::isUuid($file)) {
            return $this->lookup->find($this->workspaceId, $file)?->getUrl();
        }

        if (preg_match(self::LEGACY_FILENAME, $file) !== 1) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($file) ? $disk->url($file) : null;
    }

    public function saveUploadedFileAttachment(TemporaryUploadedFile $file): string
    {
        $user = auth()->user();
        $workspace = Workspace::query()->find($this->workspaceId);

        abort_unless($user instanceof User && $workspace instanceof Workspace, 403);

        try {
            return resolve(StorePendingUpload::class)
                ->execute($user, $workspace, $file->getRealPath(), $file->getClientOriginalName(), UploadSource::Panel)
                ->uuid;
        } catch (UploadException $exception) {
            throw ValidationException::withMessages(['attachment' => $exception->getMessage()]);
        }
    }

    public function getDefaultFileAttachmentVisibility(): string
    {
        return 'private';
    }

    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return false;
    }

    /** @param array<mixed> $exceptIds */
    public function cleanUpFileAttachments(array $exceptIds): void
    {
        // UploadClaims releases dropped images when the value is saved; a per-editor
        // cleanup would delete another member's pending draft image in the same workspace.
    }

    /**
     * The inverse of {@see self::rewriteAttachmentUrls()}. A rendered body carries
     * signed, expiring URLs; storing one re-signs it on every save, which rewrites
     * the value and logs a change the user never made.
     */
    public function canonicalize(string $html): string
    {
        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');

        foreach ($document->querySelectorAll('img') as $image) {
            $source = $image->getAttribute('src') ?? '';
            $uuid = $this->lookup->uuidFromUrl($source);

            if (! $image->hasAttribute('data-id') && $uuid !== null && $this->lookup->find($this->workspaceId, $uuid) instanceof Media) {
                $image->setAttribute('data-id', $uuid);
            }

            if ($this->getFileAttachmentUrl($image->getAttribute('data-id')) !== null) {
                $image->removeAttribute('src');
            }
        }

        foreach ($document->querySelectorAll('a[href]') as $link) {
            $href = $link->getAttribute('href') ?? '';
            $uuid = $this->lookup->uuidFromUrl($href);

            if ($uuid === null || ! $this->lookup->find($this->workspaceId, $uuid) instanceof Media) {
                continue;
            }

            $fragment = parse_url($href, PHP_URL_FRAGMENT);
            $link->setAttribute('href', route('media.show', ['media' => $uuid]).(is_string($fragment) ? '#'.$fragment : ''));
        }

        return $this->bodyHtml($document);
    }

    public function rewriteAttachmentUrls(string $html): string
    {
        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');

        foreach ($document->querySelectorAll('img[data-id], a[href]') as $element) {
            $isImage = $element->localName === 'img';
            $uuid = $isImage
                ? $element->getAttribute('data-id')
                : $this->lookup->uuidFromUrl($element->getAttribute('href') ?? '');
            $url = $this->getFileAttachmentUrl($uuid);

            if ($url !== null) {
                $fragment = $isImage ? null : parse_url($element->getAttribute('href') ?? '', PHP_URL_FRAGMENT);

                if (is_string($fragment)) {
                    $url .= '#'.$fragment;
                }

                $element->setAttribute($isImage ? 'src' : 'href', $url);
            }
        }

        return $this->bodyHtml($document);
    }

    private function bodyHtml(HTMLDocument $document): string
    {
        return $document->body->innerHTML;
    }
}
