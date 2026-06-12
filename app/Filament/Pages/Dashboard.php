<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Task\NotifyTaskAssignees;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Relaticle\Chat\Actions\ListConversations;
use Relaticle\Chat\Data\MyTaskItem;
use Relaticle\Chat\Services\MyTasksService;

final class Dashboard extends Page
{
    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

    public static function getNavigationLabel(): string
    {
        return __('filament/navigation.items.dashboard');
    }

    public function getTitle(): string
    {
        return __('filament/navigation.items.dashboard');
    }

    protected static ?int $navigationSort = -2;

    protected ?string $heading = '';

    protected string $view = 'chat::filament.pages.dashboard';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public ?string $recentChatTitle = null;

    public ?string $recentChatId = null;

    public function mount(): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        $recentChat = (new ListConversations)->execute($user, 1)->first();

        if ($recentChat) {
            $this->recentChatId = $recentChat->id;
            $this->recentChatTitle = $recentChat->title;
        }
    }

    public function getGreeting(): string
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $firstName = explode(' ', $user->name)[0];

        /** @var string $timezone */
        $timezone = $user->timezone ?? config('app.timezone');
        $hour = Date::now($timezone)->hour;

        return match (true) {
            $hour < 12 => "Good morning, {$firstName}.",
            $hour < 18 => "Good afternoon, {$firstName}.",
            default => "Good evening, {$firstName}.",
        };
    }

    /**
     * @return Collection<int, MyTaskItem>
     */
    #[Computed]
    public function myTasks(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $team = $user->currentTeam;

        return $team
            ? resolve(MyTasksService::class)->forUser($user, $team)
            : new Collection;
    }

    public function getTasksIndexUrl(): string
    {
        return TaskResource::getUrl('index', [
            'tableFilters' => ['assigned_to_me' => ['isActive' => true]],
        ]);
    }

    public function createTaskAction(): CreateAction
    {
        return $this->configureCreateTaskAction(CreateAction::make('createTask'))
            ->label(__('filament/pages/dashboard.tasks.create_action_label'));
    }

    public function createTaskHeaderAction(): CreateAction
    {
        return $this->configureCreateTaskAction(CreateAction::make('createTaskHeader'))
            ->iconButton()
            ->color('gray')
            ->label(__('filament/pages/dashboard.tasks.create_action_label'));
    }

    private function configureCreateTaskAction(CreateAction $action): CreateAction
    {
        return $action
            ->model(Task::class)
            ->icon('heroicon-o-plus')
            ->slideOver()
            ->schema(fn (Schema $schema): Schema => TaskForm::get($schema))
            ->after(function (Task $record): void {
                resolve(NotifyTaskAssignees::class)->execute($record);
            });
    }
}
