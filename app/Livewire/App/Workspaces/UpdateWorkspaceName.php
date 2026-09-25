<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Actions\Jetstream\UpdateWorkspaceName as UpdateWorkspaceNameAction;
use App\Filament\Pages\EditWorkspace;
use App\Livewire\BaseLivewireComponent;
use App\Models\Workspace;
use App\Rules\ValidWorkspaceSlug;
use App\Support\WorkspaceUrlPrefix;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

final class UpdateWorkspaceName extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public Workspace $workspace;

    public bool $slugManuallyEdited = false;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;

        $this->form->fill($workspace->only(['name', 'slug']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('workspaces.sections.update_workspace_name.title'))
                    ->aside()
                    ->description(__('workspaces.sections.update_workspace_name.description'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('workspaces.form.workspace_name.label'))
                            ->string()
                            ->maxLength(255)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($this->slugManuallyEdited) {
                                    return;
                                }

                                $set('slug', Str::slug((string) $state));
                            }),
                        TextInput::make('slug')
                            ->label(__('workspaces.form.workspace_slug.label'))
                            ->prefix(WorkspaceUrlPrefix::get())
                            ->helperText(__('workspaces.form.workspace_slug.helper_text'))
                            ->string()
                            ->maxLength(255)
                            ->required()
                            ->rules([new ValidWorkspaceSlug(ignoreValue: $this->workspace->slug)])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (): void {
                                $this->slugManuallyEdited = true;
                            })
                            ->dehydrateStateUsing(fn (?string $state): string => Str::slug((string) $state))
                            ->unique('workspaces', 'slug', ignorable: $this->workspace),
                        Actions::make([
                            Action::make('save')
                                ->label(__('workspaces.actions.save'))
                                ->action(fn () => $this->updateWorkspaceName($this->workspace)),
                        ])->alignEnd(),
                    ]),
            ])
            ->statePath('data');
    }

    public function updateWorkspaceName(Workspace $workspace): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $data = $this->form->getState();
        $oldSlug = $workspace->slug;

        resolve(UpdateWorkspaceNameAction::class)->update($this->authUser(), $workspace, $data);

        $this->sendNotification();

        if ($workspace->slug !== $oldSlug) {
            $this->redirect(EditWorkspace::getUrl(tenant: $workspace));
        }
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.update-workspace-name');
    }
}
