<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Actions\Workspace\SetWorkspaceWebSearch;
use App\Livewire\BaseLivewireComponent;
use App\Models\Workspace;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Relaticle\Chat\Enums\WebAccess;

final class WorkspaceAssistantSettings extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;

        $this->form->fill(['web_search' => $workspace->ai_settings->webSearch]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make((string) config('chat.assistant_name'))
                    ->aside()
                    ->description(__('workspaces.sections.assistant.description', ['assistant' => (string) config('chat.assistant_name')]))
                    ->schema([
                        Toggle::make('web_search')
                            ->label(__('workspaces.form.web_search.label', ['assistant' => (string) config('chat.assistant_name')]))
                            ->disabled(! WebAccess::availableOnThisInstall())
                            ->helperText(WebAccess::availableOnThisInstall()
                                ? __('workspaces.form.web_search.helper_text')
                                : __('workspaces.form.web_search.unavailable')),
                        Actions::make([
                            Action::make('save')
                                ->label(__('workspaces.actions.save'))
                                ->action(fn () => $this->updateWebSearch()),
                        ])->alignEnd(),
                    ]),
            ])
            ->statePath('data');
    }

    public function updateWebSearch(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        /** @var array{web_search: bool} $data */
        $data = $this->form->getState();

        resolve(SetWorkspaceWebSearch::class)->execute($this->authUser(), $this->workspace, (bool) $data['web_search']);

        $this->sendNotification();
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.workspace-assistant-settings');
    }
}
