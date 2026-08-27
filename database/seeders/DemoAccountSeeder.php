<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\UpdateCustomField;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\Task\UpdateTask;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\OnboardSeed\OnboardSeedManager;
use RuntimeException;

final class DemoAccountSeeder extends Seeder
{
    public const string EMAIL = 'demo@relaticle.com';

    public const string TEAM_NAME = 'Relaticle Reviewer Workspace';

    public const string TEAM_SLUG = 'relaticle-reviewer-workspace';

    public const string INACTIVE_FIELD_CODE = 'reviewer_archived_segment';

    public const string INACTIVE_FIELD_NAME = 'Archived Segment';

    public function __construct(
        private readonly CreateCustomField $createCustomField,
        private readonly OnboardSeedManager $onboardSeedManager,
        private readonly UpdateCustomField $updateCustomField,
        private readonly UpdateOpportunity $updateOpportunity,
        private readonly UpdateTask $updateTask,
    ) {}

    public function run(): void
    {
        $password = (string) config('services.demo_account.password');

        throw_if($password === '', RuntimeException::class, 'DEMO_ACCOUNT_PASSWORD is not set; refusing to seed the demo account with a repository-visible password.');

        $user = $this->reviewer($password);
        $team = $user->personalTeam();

        throw_unless($team instanceof Team, RuntimeException::class, 'Demo user has no personal team; reviewer workspace setup failed.');

        $team->forceFill([
            'name' => self::TEAM_NAME,
            'slug' => $this->reviewerSlug($team),
            'hosted_free_grandfathered_at' => now(),
            'trial_ends_at' => null,
            'scheduled_deletion_at' => null,
        ])->save();

        $user->forceFill(['current_team_id' => $team->getKey()])->save();
        $user->setRelation('currentTeam', $team);

        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($team->getKey());

        try {
            DB::transaction(function () use ($user, $team): void {
                $this->resetReviewerWorkspace($team);

                throw_unless(
                    $this->onboardSeedManager->generateFor($user, $team, 'sales'),
                    RuntimeException::class,
                    'Reviewer workspace fixtures could not be generated.',
                );

                $this->shapeOpportunities($user, $team);
                $this->shapeTasks($user, $team);
                $this->ensureInactiveField($user, $team);
                $this->recordCreationActivity($user, $team);
            });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }
    }

    private function reviewer(string $password): User
    {
        $user = User::query()->where('email', self::EMAIL)->first();
        $attributes = [
            'name' => 'Relaticle Demo',
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'scheduled_deletion_at' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];

        if (! $user instanceof User) {
            return User::factory()->withPersonalTeam()->create([
                'email' => self::EMAIL,
                ...$attributes,
            ]);
        }

        $user->forceFill($attributes)->save();

        return $user;
    }

    /**
     * Team slugs are deliberately frozen after creation (`doNotGenerateSlugsOnUpdate`),
     * so renaming the personal team to TEAM_NAME leaves it on the factory-derived slug
     * the account was created with. Reviewers read this slug in every record URL the
     * search and fetch tools return, so claim the readable one when it is available and
     * keep the existing slug when another team already holds it.
     */
    private function reviewerSlug(Team $team): string
    {
        if ($team->slug === self::TEAM_SLUG) {
            return self::TEAM_SLUG;
        }

        $taken = Team::query()
            ->where('slug', self::TEAM_SLUG)
            ->whereKeyNot($team->getKey())
            ->exists();

        return $taken ? $team->slug : self::TEAM_SLUG;
    }

    private function resetReviewerWorkspace(Team $team): void
    {
        $teamId = $team->getKey();
        $taskIds = Task::query()->withTrashed()->where('team_id', $teamId)->pluck('id');
        $noteIds = Note::query()->withTrashed()->where('team_id', $teamId)->pluck('id');

        DB::table('taskables')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_user')->whereIn('task_id', $taskIds)->delete();
        DB::table('noteables')->whereIn('note_id', $noteIds)->delete();
        DB::table('custom_field_values')->where('tenant_id', $teamId)->delete();
        Activity::query()->withoutGlobalScopes()->where('team_id', $teamId)->delete();

        Model::withoutEvents(function () use ($teamId): void {
            Note::query()->withTrashed()->where('team_id', $teamId)->forceDelete();
            Task::query()->withTrashed()->where('team_id', $teamId)->forceDelete();
            Opportunity::query()->withTrashed()->where('team_id', $teamId)->forceDelete();
            People::query()->withTrashed()->where('team_id', $teamId)->forceDelete();
            Company::query()->withTrashed()->where('team_id', $teamId)->forceDelete();
        });
    }

    private function shapeOpportunities(User $user, Team $team): void
    {
        $stageField = $this->field($team, 'opportunity', 'stage');
        $stageIds = $stageField->options()
            ->withoutGlobalScopes()
            ->pluck('id', 'name');

        /** @var array<string, array{person: string, stage: string}> $specifications */
        $specifications = [
            'Apple Developer Partnership' => ['person' => 'Tim Cook', 'stage' => 'Closed Won'],
            'Airbnb Host Analytics Platform' => ['person' => 'Brian Chesky', 'stage' => 'Closed Lost'],
            'Figma Enterprise Plan' => ['person' => 'Dylan Field', 'stage' => 'Proposal/Price Quote'],
            'Notion API Integration' => ['person' => 'Ivan Zhao', 'stage' => 'Qualification'],
        ];

        foreach ($specifications as $name => $specification) {
            $opportunity = Opportunity::query()
                ->where('team_id', $team->getKey())
                ->where('name', $name)
                ->firstOrFail();
            $person = People::query()
                ->where('team_id', $team->getKey())
                ->where('name', $specification['person'])
                ->firstOrFail();

            $this->updateOpportunity->execute($user, $opportunity, [
                'contact_id' => $person->getKey(),
                'custom_fields' => [
                    'stage' => $stageIds->get($specification['stage']),
                ],
            ]);
        }
    }

    private function shapeTasks(User $user, Team $team): void
    {
        $statusField = $this->field($team, 'task', 'status');
        $statusIds = $statusField->options()
            ->withoutGlobalScopes()
            ->pluck('id', 'name');

        /** @var array<string, array{company: string, person: string, opportunity: string, status: string, due_at: Carbon, assigned: bool}> $specifications */
        $specifications = [
            'Follow up with Dylan' => [
                'company' => 'Figma',
                'person' => 'Dylan Field',
                'opportunity' => 'Figma Enterprise Plan',
                'status' => 'To do',
                'due_at' => now()->subDays(2),
                'assigned' => true,
            ],
            'Discovery call with Brian' => [
                'company' => 'Airbnb',
                'person' => 'Brian Chesky',
                'opportunity' => 'Airbnb Host Analytics Platform',
                'status' => 'To do',
                'due_at' => now()->addDays(2),
                'assigned' => false,
            ],
            'Send proposal to Tim' => [
                'company' => 'Apple',
                'person' => 'Tim Cook',
                'opportunity' => 'Apple Developer Partnership',
                'status' => 'Done',
                'due_at' => now()->subDay(),
                'assigned' => true,
            ],
            'Integration meeting with Ivan' => [
                'company' => 'Notion',
                'person' => 'Ivan Zhao',
                'opportunity' => 'Notion API Integration',
                'status' => 'In progress',
                'due_at' => now()->addDays(5),
                'assigned' => true,
            ],
        ];

        foreach ($specifications as $title => $specification) {
            $task = Task::query()
                ->where('team_id', $team->getKey())
                ->where('title', $title)
                ->firstOrFail();
            $company = Company::query()
                ->where('team_id', $team->getKey())
                ->where('name', $specification['company'])
                ->firstOrFail();
            $person = People::query()
                ->where('team_id', $team->getKey())
                ->where('name', $specification['person'])
                ->firstOrFail();
            $opportunity = Opportunity::query()
                ->where('team_id', $team->getKey())
                ->where('name', $specification['opportunity'])
                ->firstOrFail();

            $this->updateTask->execute($user, $task, [
                'company_ids' => [$company->getKey()],
                'people_ids' => [$person->getKey()],
                'opportunity_ids' => [$opportunity->getKey()],
                'assignee_ids' => $specification['assigned'] ? [$user->getKey()] : [],
                'custom_fields' => [
                    'status' => $statusIds->get($specification['status']),
                    'due_date' => $specification['due_at']->utc()->format('Y-m-d H:i:s'),
                ],
            ]);
        }
    }

    private function ensureInactiveField(User $user, Team $team): void
    {
        $field = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $team->getKey())
            ->where('entity_type', 'company')
            ->where('code', self::INACTIVE_FIELD_CODE)
            ->first();

        if (! $field instanceof CustomField) {
            $field = $this->createCustomField->execute($user, [
                'entity_type' => 'company',
                'type' => 'text',
                'name' => self::INACTIVE_FIELD_NAME,
                'code' => self::INACTIVE_FIELD_CODE,
            ]);
        }

        $this->updateCustomField->execute($user, $field, [
            'name' => self::INACTIVE_FIELD_NAME,
            'active' => false,
        ]);
    }

    private function recordCreationActivity(User $user, Team $team): void
    {
        $companies = Company::query()
            ->where('team_id', $team->getKey())
            ->orderBy('name')
            ->get();

        foreach ($companies as $company) {
            activity('crm')
                ->causedBy($user)
                ->performedOn($company)
                ->event('created')
                ->withProperties(['attributes' => ['name' => $company->name]])
                ->log('created');
        }
    }

    private function field(Team $team, string $entityType, string $code): CustomField
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $team->getKey())
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->with(['options' => fn (Relation $query): Relation => $query->withoutGlobalScopes()])
            ->firstOrFail();
    }
}
