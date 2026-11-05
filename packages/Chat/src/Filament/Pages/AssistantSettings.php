<?php

declare(strict_types=1);

namespace Relaticle\Chat\Filament\Pages;

use App\Contracts\Entitlements;
use App\Filament\Clusters\Settings;
use App\Models\User;
use App\Models\Workspace;
use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Relaticle\Chat\Actions\SetWebSearchPreference;
use Relaticle\Chat\Enums\WebAccess;
use Relaticle\Chat\Enums\WebSearchPreference;

/**
 * @property-read Schema $form
 */
final class AssistantSettings extends Page
{
    protected string $view = 'chat::filament.pages.assistant-settings';

    protected static string $layout = 'filament.layouts.settings';

    protected static ?string $slug = 'assistant';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = Settings::class;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return (string) config('chat.assistant_name');
    }

    public function getHeading(): string
    {
        return (string) config('chat.assistant_name');
    }

    public static function getLabel(): string
    {
        return (string) config('chat.assistant_name');
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
                Section::make(__('Web search'))->schema([
                    Radio::make('web_search')
                        ->label(__('When :name wants to search the web', ['name' => (string) config('chat.assistant_name')]))
                        ->options(collect(WebSearchPreference::cases())->mapWithKeys(
                            fn (WebSearchPreference $preference): array => [$preference->value => $preference->getLabel()],
                        )->all())
                        ->required()
                        ->disabled(! $workspaceAllows)
                        ->helperText($this->helperText($workspaceAllows))
                        ->live()
                        ->afterStateUpdated(function (): void {
                            $this->save();
                        }),
                ]),
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

    private function helperText(bool $workspaceAllows): string
    {
        $workspace = Filament::getTenant();

        return match (true) {
            ! WebAccess::availableOnThisInstall() => __('No model on this install can search the web.'),
            ! $workspaceAllows => __('Your workspace owner turned web search off.'),
            $workspace instanceof Workspace && resolve(Entitlements::class)->metersCredits($workspace) => __('Search queries go to the AI provider\'s web search. Each search uses :count credits.', ['count' => (int) config('chat.web_search_credit_cost')]),
            default => __('Search queries go to the AI provider\'s web search.'),
        };
    }

    private function workspaceAllowsWebSearch(): bool
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace && $workspace->aiSettings()->webSearch() && WebAccess::availableOnThisInstall();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
