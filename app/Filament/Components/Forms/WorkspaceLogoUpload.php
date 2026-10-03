<?php

declare(strict_types=1);

namespace App\Filament\Components\Forms;

use App\Models\Workspace;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Support\Number;
use Override;

final class WorkspaceLogoUpload extends SpatieMediaLibraryFileUpload
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // image() resets the allowlist to `image/*`, so the explicit list goes after it.
        $this->image()
            ->acceptedFileTypes(Workspace::LOGO_MIME_TYPES)
            ->maxSize(Workspace::LOGO_MAX_KILOBYTES)
            ->collection(Workspace::LOGO_MEDIA_COLLECTION)
            ->hiddenLabel();
    }

    public function getDescription(): string
    {
        return (string) __('uploads.logo.description', ['max' => Number::fileSize(Workspace::LOGO_MAX_KILOBYTES * 1024)]);
    }

    #[Override]
    public function toEmbeddedHtml(): string
    {
        return view('filament.forms.components.workspace-logo-upload', ['field' => $this])->render();
    }
}
