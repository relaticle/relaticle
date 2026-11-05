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

final class WorkspaceWebSearchSettings extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;

        $this->form->fill($workspace->only(['ai_web_search_enabled']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('workspaces.sections.web_search.title'))
                    ->aside()
                    ->description(__('workspaces.sections.web_search.description', ['assistant' => (string) config('chat.assistant_name')]))
                    ->schema([
                        Toggle::make('ai_web_search_enabled')
                            ->label(__('workspaces.form.ai_web_search_enabled.label', ['assistant' => (string) config('chat.assistant_name')]))
                            ->helperText(__('workspaces.form.ai_web_search_enabled.helper_text')),
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

        /** @var array{ai_web_search_enabled: bool} $data */
        $data = $this->form->getState();

        resolve(SetWorkspaceWebSearch::class)->execute($this->authUser(), $this->workspace, (bool) $data['ai_web_search_enabled']);

        $this->sendNotification();
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.workspace-web-search-settings');
    }
}
