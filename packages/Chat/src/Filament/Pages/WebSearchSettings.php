<?php

declare(strict_types=1);

namespace Relaticle\Chat\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\User;
use App\Models\Workspace;
use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Relaticle\Chat\Actions\SetWebSearchPreference;
use Relaticle\Chat\Enums\WebSearchPreference;

/**
 * @property-read Schema $form
 */
final class WebSearchSettings extends Page
{
    protected string $view = 'chat::filament.pages.web-search-settings';

    protected static string $layout = 'filament.layouts.settings';

    protected static ?string $slug = 'web-search';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = Settings::class;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Web search');
    }

    public function getHeading(): string
    {
        return __('Web search');
    }

    public static function getLabel(): string
    {
        return __('Web search');
    }

    public function mount(): void
    {
        $this->form->fill(['web_search' => WebSearchPreference::of($this->user())->value]);
    }

    public function form(Schema $schema): Schema
    {
        $workspaceAllows = $this->workspaceAllowsWebSearch();

        return $schema
            ->components([
                Radio::make('web_search')
                    ->label(__('When :name wants to search the web', ['name' => (string) config('chat.assistant_name')]))
                    ->options(collect(WebSearchPreference::cases())->mapWithKeys(
                        fn (WebSearchPreference $preference): array => [$preference->value => $preference->getLabel()],
                    )->all())
                    ->required()
                    ->disabled(! $workspaceAllows)
                    ->helperText($workspaceAllows
                        ? __('Search queries go to the AI provider\'s web search. Each search uses 2 credits.')
                        : __('Your workspace admin turned web search off.'))
                    ->live()
                    ->afterStateUpdated(function (): void {
                        $this->save();
                    }),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        if (! $this->workspaceAllowsWebSearch()) {
            return;
        }

        /** @var array{web_search: string} $state */
        $state = $this->form->getState();

        resolve(SetWebSearchPreference::class)->execute($this->user(), WebSearchPreference::from($state['web_search']));

        Notification::make()->title(__('Saved'))->success()->send();
    }

    private function workspaceAllowsWebSearch(): bool
    {
        $workspace = Filament::getTenant();

        return ! $workspace instanceof Workspace || $workspace->ai_web_search_enabled;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
