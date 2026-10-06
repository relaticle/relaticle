<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Task\CompleteTask;
use App\Filament\Actions\CreateTaskAction;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Relaticle\Chat\Data\MyTaskItem;
use Relaticle\Chat\Queries\ConversationsQuery;
use Relaticle\Chat\Services\MyTasksService;

final class Dashboard extends Page
{
    public const string AFTER_COMPOSER_RENDER_HOOK = 'dashboard.after-composer';

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

        $recentChat = new ConversationsQuery()->recent($user, 1)->first();

        if ($recentChat) {
            $this->recentChatId = $recentChat->id;
            $this->recentChatTitle = $recentChat->title;
        }
    }

    public function getGreeting(): string
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return self::greetingFor($user, explode(' ', $user->name)[0]);
    }

    public static function greetingFor(User $user, string $firstName): string
    {
        // The browser reports its timezone only after the first render, so the
        // local hour is unknown on the very first visit: greet without the clock.
        if ($user->timezone === null) {
            return __('Welcome, :name.', ['name' => $firstName]);
        }

        $hour = Date::now($user->effectiveTimezone())->hour;

        return match (true) {
            $hour < 12 => __('Good morning, :name.', ['name' => $firstName]),
            $hour < 18 => __('Good afternoon, :name.', ['name' => $firstName]),
            default => __('Good evening, :name.', ['name' => $firstName]),
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
        $workspace = $user->currentWorkspace;

        return $workspace
            ? resolve(MyTasksService::class)->forUser($user, $workspace)
            : new Collection;
    }

    #[Computed]
    public function canCompleteTasks(): bool
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $workspace = $user->currentWorkspace;

        return $workspace !== null && resolve(MyTasksService::class)->hasDoneOption($workspace);
    }

    public function completeTask(string $taskId): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        // Scoped to the current tenant: the status custom field resolves against
        // it, so a task from another of the user's workspaces would get a foreign
        // field id written onto it. A row that no longer resolves (completed in
        // another tab, deleted meanwhile) is not an error: the desired end state
        // is already true, so just refresh instead of throwing a 404 over Home.
        $task = Task::query()->where('workspace_id', Filament::getTenant()?->getKey())->find($taskId);

        if ($task instanceof Task) {
            resolve(CompleteTask::class)->execute($user, $task);
        }

        unset($this->myTasks);
    }

    public function getTasksIndexUrl(): string
    {
        return TaskResource::getUrl('index', [
            'tableFilters' => ['assigned_to_me' => ['isActive' => true]],
        ]);
    }

    public function createTaskAction(): CreateTaskAction
    {
        return $this->configureCreateTaskAction(CreateTaskAction::make('createTask'))
            ->color('gray')
            ->label(__('filament/pages/dashboard.tasks.create_action_label'));
    }

    public function createTaskHeaderAction(): CreateTaskAction
    {
        return $this->configureCreateTaskAction(CreateTaskAction::make('createTaskHeader'))
            ->iconButton()
            ->color('gray')
            ->label(__('filament/pages/dashboard.tasks.create_action_label'));
    }

    private function configureCreateTaskAction(CreateTaskAction $action): CreateTaskAction
    {
        return $action
            ->model(Task::class)
            ->icon('heroicon-o-plus')
            ->slideOver()
            ->schema(fn (Schema $schema): Schema => TaskForm::get($schema));
    }
}
