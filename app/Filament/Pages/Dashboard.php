<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Onboarding\DismissSetupOpener;
use App\Actions\Task\CompleteTask;
use App\Actions\Task\NotifyTaskAssignees;
use App\Features\SetupConversation;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceActivationFacts;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Relaticle\Chat\Actions\ListConversations;
use Relaticle\Chat\Data\MyTaskItem;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Services\MyTasksService;
use Relaticle\Chat\Support\MarkdownRenderer;

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

    public bool $recentChatIsSetup = false;

    public ?string $setupConversationId = null;

    public ?string $setupOpenerHtml = null;

    public function mount(): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $workspace = $user->currentWorkspace;
        $setup = $workspace instanceof Workspace ? $workspace->setupConversation : null;

        if ($setup instanceof AgentConversation && $this->shouldShowOpener($user, $workspace, $setup)) {
            $this->setupConversationId = $setup->id;
            $this->setupOpenerHtml = resolve(MarkdownRenderer::class)->render($this->openerMarkdown($setup));
        }

        if ($setup instanceof AgentConversation
            && Feature::active(SetupConversation::class)
            && $setup->participant_id === (string) $user->getKey()
            && ! resolve(WorkspaceActivationFacts::class)->hasOwnRecord($workspace)) {
            $this->recentChatId = $setup->id;
            $this->recentChatTitle = __('onboarding/setup.continue');
            $this->recentChatIsSetup = true;

            return;
        }

        $recentChat = (new ListConversations)->execute($user, 1)->first();

        if ($recentChat) {
            $this->recentChatId = $recentChat->id;
            $this->recentChatTitle = $recentChat->title;
        }
    }

    public function dismissSetupOpener(): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $workspace = $user->currentWorkspace;

        if (! $workspace instanceof Workspace) {
            return;
        }

        resolve(DismissSetupOpener::class)->execute($user, $workspace);

        $this->redirect(self::getUrl(), navigate: true);
    }

    private function shouldShowOpener(User $user, Workspace $workspace, AgentConversation $setup): bool
    {
        if (! Feature::active(SetupConversation::class)) {
            return false;
        }

        if ($setup->participant_id !== (string) $user->getKey()) {
            return false;
        }

        if ($workspace->onboarding_opener_dismissed_at !== null) {
            return false;
        }

        return ! $setup->messages()->where('role', 'user')->exists();
    }

    private function openerMarkdown(AgentConversation $setup): string
    {
        return (string) $setup->messages()
            ->where('role', 'assistant')
            ->orderBy('id')
            ->value('content');
    }

    public function getGreeting(): string
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $firstName = explode(' ', $user->name)[0];

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

    public function createTaskAction(): CreateAction
    {
        return $this->configureCreateTaskAction(CreateAction::make('createTask'))
            ->color('gray')
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
        /** @var array<int, string> $submittedAssigneeIds */
        $submittedAssigneeIds = [];

        return $action
            ->model(Task::class)
            ->icon('heroicon-o-plus')
            ->slideOver()
            ->schema(fn (Schema $schema): Schema => TaskForm::get($schema))
            ->before(function () use ($action, &$submittedAssigneeIds): void {
                $submittedAssignees = $action->getRawData()['assignees'] ?? [];

                if (! is_array($submittedAssignees)) {
                    $submittedAssignees = [];
                }

                $submittedAssigneeIds = array_values(array_filter($submittedAssignees, is_string(...)));
            })
            ->after(function (Task $record) use (&$submittedAssigneeIds): void {
                resolve(NotifyTaskAssignees::class)->execute($record, $submittedAssigneeIds);
            });
    }
}
