<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Task\CompleteTask;
use App\Actions\Task\NotifyTaskAssignees;
use App\Enums\ActivationStep;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\WorkspaceActivationFacts;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Relaticle\Chat\Actions\ListConversations;
use Relaticle\Chat\Data\MyTaskItem;
use Relaticle\Chat\Services\MyTasksService;
use Relaticle\Chat\Support\MarkdownRenderer;
use Spatie\Onboard\OnboardingStep;

/**
 * @property-read array{conversation_id: string, html: string}|null $welcome
 * @property-read array{label: string, prompt: string}|null $nextAction
 */
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

        $hour = Date::now($user->effectiveTimezone())->hour;

        return match (true) {
            $hour < 12 => __('Good morning, :name.', ['name' => $firstName]),
            $hour < 18 => __('Good afternoon, :name.', ['name' => $firstName]),
            default => __('Good evening, :name.', ['name' => $firstName]),
        };
    }

    /**
     * Rela's seeded welcome message, when this user should still see it on the
     * dashboard. Null is the "not first run" signal: the user has replied, the
     * checklist was dismissed, the conversation belongs to somebody else, or the
     * workspace predates the welcome.
     *
     * @return array{conversation_id: string, html: string}|null
     */
    #[Computed]
    public function welcome(): ?array
    {
        $team = Filament::getTenant();

        if (! $team instanceof Team || $team->activation_checklist_dismissed_at !== null) {
            return null;
        }

        /** @var User $user */
        $user = Filament::auth()->user();

        $message = DB::table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.team_id', $team->getKey())
            ->where('c.participant_type', $user->getMorphClass())
            ->where('c.participant_id', (string) $user->getKey())
            ->whereRaw("coalesce(m.meta->>'welcome', '') = 'true'")
            ->orderBy('m.id')
            ->first(['m.content', 'm.conversation_id']);

        if ($message === null) {
            return null;
        }

        if (resolve(WorkspaceActivationFacts::class)->hasUserChatMessage($team)) {
            return null;
        }

        return [
            'conversation_id' => (string) $message->conversation_id,
            'html' => (new MarkdownRenderer)->render((string) $message->content),
        ];
    }

    /**
     * The single next action offered under Rela's welcome: the highest-priority
     * unfinished activation step. AskRela is excluded because pressing the
     * button completes it.
     *
     * @return array{label: string, prompt: string}|null
     */
    #[Computed]
    public function nextAction(): ?array
    {
        if ($this->welcome === null) {
            return null;
        }

        $team = Filament::getTenant();

        if (! $team instanceof Team) {
            return null;
        }

        $steps = $team->onboarding()->steps();

        foreach ([ActivationStep::FirstRecord, ActivationStep::Import, ActivationStep::Invite] as $candidate) {
            $step = $steps->first(function (OnboardingStep $step) use ($candidate): bool {
                $key = $step->attribute('key');

                return ($key instanceof ActivationStep ? $key : ActivationStep::from((string) $key)) === $candidate;
            });

            if ($step instanceof OnboardingStep && $step->incomplete()) {
                return [
                    'label' => __("filament/pages/dashboard.activation.next_action.{$candidate->value}.label"),
                    'prompt' => __("filament/pages/dashboard.activation.next_action.{$candidate->value}.prompt"),
                ];
            }
        }

        return null;
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

    #[Computed]
    public function canCompleteTasks(): bool
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $team = $user->currentTeam;

        return $team !== null && resolve(MyTasksService::class)->hasDoneOption($team);
    }

    public function completeTask(string $taskId): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        // Scoped to the current tenant: the status custom field resolves against
        // it, so a task from another of the user's teams would get a foreign
        // field id written onto it. A row that no longer resolves (completed in
        // another tab, deleted meanwhile) is not an error: the desired end state
        // is already true, so just refresh instead of throwing a 404 over Home.
        $task = Task::query()->where('team_id', Filament::getTenant()?->getKey())->find($taskId);

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
