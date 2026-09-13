<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Livewire\BaseLivewireComponent;
use App\Models\Workspace;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

final class UpdateWorkspaceLogo extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;

        $this->form->fill();

        // fill() hydrates defaults only; the media collection needs its own load.
        $this->form->loadStateFromRelationships(shouldHydrate: true);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->workspace)
            ->schema([
                Section::make(__('workspaces.sections.update_workspace_logo.title'))
                    ->aside()
                    ->description(__('workspaces.sections.update_workspace_logo.description'))
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('logo')
                            ->label(__('workspaces.form.workspace_logo.label'))
                            ->collection(Workspace::LOGO_MEDIA_COLLECTION)
                            ->imageEditor()
                            ->avatar()
                            // Last in the chain on purpose: avatar() calls image(),
                            // which resets the allowlist back to `image/*`.
                            ->acceptedFileTypes(Workspace::LOGO_MIME_TYPES)
                            ->maxSize(Workspace::LOGO_MAX_KILOBYTES),
                        Actions::make([
                            Action::make('save')
                                ->label(__('profile.actions.save'))
                                ->submit('updateLogo'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function updateLogo(): void
    {
        Gate::authorize('update', $this->workspace);

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $this->form->getState();

        $this->sendNotification();
    }
}
