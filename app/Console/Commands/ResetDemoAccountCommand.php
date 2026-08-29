<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Chat\SeedTeamCreditBalance;
use App\Actions\Company\CreateCompany;
use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\UpdateCustomField;
use App\Actions\Note\CreateNote;
use App\Actions\Opportunity\CreateOpportunity;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\People\CreatePeople;
use App\Actions\Task\CreateTask;
use App\Actions\Task\UpdateTask;
use App\Enums\CreationSource;
use App\Jobs\FetchFaviconForCompany;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\OnboardSeed\OnboardSeedManager;
use RuntimeException;

#[Description('Create the demo account for directory reviewers, or reset its workspace back to the pristine fixture')]
#[Signature('demo:reset
                            {--password= : Password for the demo account; required on first run, keeps the current password when omitted}
                            {--force : Run without confirmation in production}')]
final class ResetDemoAccountCommand extends Command
{
    use ConfirmableTrait;

    public const string EMAIL = 'demo@relaticle.com';

    public const string TEAM_NAME = 'Relaticle Reviewer Workspace';

    public const string TEAM_SLUG = 'relaticle-reviewer-workspace';

    public const string INACTIVE_FIELD_CODE = 'reviewer_archived_segment';

    public const string INACTIVE_FIELD_NAME = 'Archived Segment';

    public function __construct(
        private readonly CreateCompany $createCompany,
        private readonly CreateCustomField $createCustomField,
        private readonly CreateNote $createNote,
        private readonly CreateOpportunity $createOpportunity,
        private readonly CreatePeople $createPeople,
        private readonly CreateTask $createTask,
        private readonly OnboardSeedManager $onboardSeedManager,
        private readonly SeedTeamCreditBalance $seedTeamCreditBalance,
        private readonly UpdateCustomField $updateCustomField,
        private readonly UpdateOpportunity $updateOpportunity,
        private readonly UpdateTask $updateTask,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->confirmToProceed('The demo workspace will be wiped and rebuilt')) {
            return self::FAILURE;
        }

        $password = $this->password();
        $existing = User::query()->where('email', self::EMAIL)->first();

        if (! $existing instanceof User && $password === null) {
            $this->components->error('Pass --password to create the demo account; it is never created with a repository-visible password.');

            return self::FAILURE;
        }

        $user = $this->reviewer($existing, $password);
        $team = $user->ownedTeams()->where('personal_team', true)->first();

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

        // Records are created through the same actions the app uses, and those stamp
        // team_id and creator_id from the authenticated user.
        Auth::setUser($user);

        $seededCompanies = new EloquentCollection;

        try {
            DB::transaction(function () use ($user, $team, &$seededCompanies): void {
                $this->resetReviewerWorkspace($team);

                throw_unless(
                    $this->onboardSeedManager->generateFor($user, $team, 'sales'),
                    RuntimeException::class,
                    'Reviewer workspace fixtures could not be generated.',
                );

                $seededCompanies = Company::query()->where('team_id', $team->getKey())->get();

                $this->resetAiCredits($team);
                $this->shapeOpportunities($user, $team);
                $this->shapeTasks($user, $team);
                $this->expandWorkspace($user, $team);
                $this->ensureInactiveField($user, $team);
                $this->recordCreationActivity($user, $team);
            });
            $this->fetchCompanyLogos($seededCompanies);
        } finally {
            Auth::forgetUser();
            TenantContextService::setTenantId($previousTenantId);
        }

        $this->components->info(sprintf('Demo account %s is ready in workspace "%s" (%s).', self::EMAIL, $team->name, $team->slug));
        $this->components->twoColumnDetail('Password', $password === null ? 'unchanged' : 'updated');

        return self::SUCCESS;
    }

    // OnboardSeed writes its fixtures with model events disabled, so the observer that
    // normally queues a logo fetch never fires for them. Everything created afterwards
    // goes through the actions, and the observer covers those.
    /** @param  EloquentCollection<int, Company>  $companies */
    private function fetchCompanyLogos(EloquentCollection $companies): void
    {
        $companies->each(function (Company $company): void {
            dispatch(new FetchFaviconForCompany($company));
        });
    }

    private function password(): ?string
    {
        $option = $this->option('password');

        return is_string($option) && $option !== '' ? $option : null;
    }

    private function reviewer(?User $user, ?string $password): User
    {
        $attributes = [
            'name' => 'Relaticle Demo',
            'email_verified_at' => now(),
            'scheduled_deletion_at' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];

        if ($password !== null) {
            $attributes['password'] = Hash::make($password);
        }

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

    // Child rows are deleted explicitly rather than left to `on delete cascade`,
    // because production is missing several of the foreign keys this schema declares.
    private function resetReviewerWorkspace(Team $team): void
    {
        $teamId = $team->getKey();
        $taskIds = Task::query()->withTrashed()->where('team_id', $teamId)->pluck('id');
        $noteIds = Note::query()->withTrashed()->where('team_id', $teamId)->pluck('id');
        $conversationIds = DB::table('agent_conversations')->where('team_id', $teamId)->pluck('id');

        DB::table('taskables')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_user')->whereIn('task_id', $taskIds)->delete();
        DB::table('noteables')->whereIn('note_id', $noteIds)->delete();
        DB::table('custom_field_values')->where('tenant_id', $teamId)->delete();
        DB::table('chat_message_feedback')->where('team_id', $teamId)->delete();
        DB::table('pending_actions')->where('team_id', $teamId)->delete();
        DB::table('agent_conversation_messages')->whereIn('conversation_id', $conversationIds)->delete();
        DB::table('agent_conversations')->where('team_id', $teamId)->delete();
        DB::table('failed_import_rows')->where('team_id', $teamId)->delete();
        DB::table('imports')->where('team_id', $teamId)->delete();
        DB::table('exports')->where('team_id', $teamId)->delete();
        DB::table('team_invitations')->where('team_id', $teamId)->delete();
        Activity::query()->withoutGlobalScopes()->where('team_id', $teamId)->delete();

        Company::query()
            ->where('team_id', $teamId)
            ->each(function (Company $company): void {
                $company->clearMediaCollection(Company::LOGO_MEDIA_COLLECTION);
            });

        Model::withoutEvents(function () use ($teamId): void {
            Note::query()->withTrashed()->where('team_id', $teamId)->forceDelete();
            Task::query()->withTrashed()->where('team_id', $teamId)->forceDelete();
            Opportunity::query()->withTrashed()->where('team_id', $teamId)->forceDelete();
            People::query()->withTrashed()->where('team_id', $teamId)->forceDelete();
            Company::query()->withTrashed()->where('team_id', $teamId)->forceDelete();
        });
    }

    private function resetAiCredits(Team $team): void
    {
        DB::table('ai_credit_transactions')->where('team_id', $team->getKey())->delete();
        DB::table('ai_credit_balances')->where('team_id', $team->getKey())->delete();

        $this->seedTeamCreditBalance->execute($team);
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

    // The onboarding fixture set is too small for reviewers to exercise pagination,
    // filters and aggregation, so the pipeline is deepened on top of it.
    private function expandWorkspace(User $user, Team $team): void
    {
        $companies = $this->createCompanies($user);
        $people = $this->createPeople($user, $companies);
        $opportunities = $this->createOpportunities($user, $team, $companies, $people);

        $this->createTasks($user, $team, $companies, $people, $opportunities);
        $this->createNotes($user, $companies, $people, $opportunities);
    }

    /**
     * @return array<string, Company>
     */
    private function createCompanies(User $user): array
    {
        $companies = [];

        foreach ($this->companyFixtures() as $name => $fixture) {
            $companies[$name] = $this->createCompany->execute($user, [
                'name' => $name,
                'account_owner_id' => $user->getKey(),
                'custom_fields' => [
                    'domains' => $fixture['domain'],
                    'icp' => $fixture['icp'],
                    'linkedin' => 'www.linkedin.com/company/'.$fixture['handle'],
                ],
            ], CreationSource::SYSTEM);
        }

        return $companies;
    }

    /**
     * @param  array<string, Company>  $companies
     * @return array<string, People>
     */
    private function createPeople(User $user, array $companies): array
    {
        $people = [];

        foreach ($this->peopleFixtures() as $name => $fixture) {
            $company = $companies[$fixture['company']];

            $people[$name] = $this->createPeople->execute($user, [
                'name' => $name,
                'company_id' => $company->getKey(),
                'custom_fields' => [
                    'emails' => [Str::slug($name, '.').'@'.$this->companyFixtures()[$fixture['company']]['domain']],
                    'phone_number' => $fixture['phone'],
                    'job_title' => $fixture['title'],
                    'linkedin' => 'www.linkedin.com/in/'.Str::slug($name),
                ],
            ], CreationSource::SYSTEM);
        }

        return $people;
    }

    /**
     * @param  array<string, Company>  $companies
     * @param  array<string, People>  $people
     * @return array<string, Opportunity>
     */
    private function createOpportunities(User $user, Team $team, array $companies, array $people): array
    {
        $stageIds = $this->field($team, 'opportunity', 'stage')
            ->options()
            ->withoutGlobalScopes()
            ->pluck('id', 'name');

        $opportunities = [];

        foreach ($this->opportunityFixtures() as $name => $fixture) {
            $opportunities[$name] = $this->createOpportunity->execute($user, [
                'name' => $name,
                'company_id' => $companies[$fixture['company']]->getKey(),
                'contact_id' => $people[$fixture['contact']]->getKey(),
                'custom_fields' => [
                    'amount' => $fixture['amount'],
                    'close_date' => now()->addDays($fixture['closes_in_days']),
                    'stage' => $stageIds->get($fixture['stage']),
                ],
            ], CreationSource::SYSTEM);
        }

        return $opportunities;
    }

    /**
     * @param  array<string, Company>  $companies
     * @param  array<string, People>  $people
     * @param  array<string, Opportunity>  $opportunities
     */
    private function createTasks(User $user, Team $team, array $companies, array $people, array $opportunities): void
    {
        $statusIds = $this->field($team, 'task', 'status')
            ->options()
            ->withoutGlobalScopes()
            ->pluck('id', 'name');
        $priorityIds = $this->field($team, 'task', 'priority')
            ->options()
            ->withoutGlobalScopes()
            ->pluck('id', 'name');

        foreach ($this->taskFixtures() as $title => $fixture) {
            $opportunity = $opportunities[$fixture['opportunity']] ?? null;

            $this->createTask->execute($user, [
                'title' => $title,
                'company_ids' => [$companies[$fixture['company']]->getKey()],
                'people_ids' => [$people[$fixture['contact']]->getKey()],
                'opportunity_ids' => $opportunity instanceof Opportunity ? [$opportunity->getKey()] : [],
                'assignee_ids' => $fixture['assigned'] ? [$user->getKey()] : [],
                'custom_fields' => [
                    'description' => $fixture['description'],
                    'due_date' => now()->addDays($fixture['due_in_days']),
                    'status' => $statusIds->get($fixture['status']),
                    'priority' => $priorityIds->get($fixture['priority']),
                ],
            ], CreationSource::SYSTEM);
        }
    }

    /**
     * @param  array<string, Company>  $companies
     * @param  array<string, People>  $people
     * @param  array<string, Opportunity>  $opportunities
     */
    private function createNotes(User $user, array $companies, array $people, array $opportunities): void
    {
        foreach ($this->noteFixtures() as $title => $fixture) {
            $contact = $people[$fixture['contact']] ?? null;
            $opportunity = $opportunities[$fixture['opportunity']] ?? null;

            $this->createNote->execute($user, [
                'title' => $title,
                'company_ids' => [$companies[$fixture['company']]->getKey()],
                'people_ids' => $contact instanceof People ? [$contact->getKey()] : [],
                'opportunity_ids' => $opportunity instanceof Opportunity ? [$opportunity->getKey()] : [],
                'custom_fields' => [
                    'body' => '<p>'.$fixture['body'].'</p>',
                ],
            ], CreationSource::SYSTEM);
        }
    }

    /**
     * @return array<string, array{domain: string, handle: string, icp: bool}>
     */
    private function companyFixtures(): array
    {
        return [
            'Stripe' => ['domain' => 'stripe.com', 'handle' => 'stripe', 'icp' => true],
            'Linear' => ['domain' => 'linear.app', 'handle' => 'linear', 'icp' => true],
            'Vercel' => ['domain' => 'vercel.com', 'handle' => 'vercel', 'icp' => true],
            'Slack' => ['domain' => 'slack.com', 'handle' => 'tiny-spec-inc', 'icp' => false],
            'Canva' => ['domain' => 'canva.com', 'handle' => 'canva', 'icp' => false],
            'Shopify' => ['domain' => 'shopify.com', 'handle' => 'shopify', 'icp' => true],
            'Datadog' => ['domain' => 'datadoghq.com', 'handle' => 'datadog', 'icp' => true],
            'Ramp' => ['domain' => 'ramp.com', 'handle' => 'ramp', 'icp' => true],
            'Retool' => ['domain' => 'retool.com', 'handle' => 'retool', 'icp' => true],
            'Loom' => ['domain' => 'loom.com', 'handle' => 'loomhq', 'icp' => false],
            'Miro' => ['domain' => 'miro.com', 'handle' => 'mirohq', 'icp' => false],
            'Webflow' => ['domain' => 'webflow.com', 'handle' => 'webflow-inc-', 'icp' => true],
            'Zapier' => ['domain' => 'zapier.com', 'handle' => 'zapier', 'icp' => true],
            'Intercom' => ['domain' => 'intercom.com', 'handle' => 'intercom', 'icp' => true],
            'Airtable' => ['domain' => 'airtable.com', 'handle' => 'airtable', 'icp' => true],
            'Amplitude' => ['domain' => 'amplitude.com', 'handle' => 'amplitude-analytics', 'icp' => true],
        ];
    }

    // Contacts are invented rather than mirrored from the real companies above: a
    // reviewer workspace must not ship plausible work addresses for real people.
    /** @return array<string, array{company: string, title: string, phone: string}> */
    private function peopleFixtures(): array
    {
        return [
            'Marcus Webb' => ['company' => 'Stripe', 'title' => 'VP Revenue Operations', 'phone' => '+14155550110'],
            'Priya Raman' => ['company' => 'Stripe', 'title' => 'Head of Partnerships', 'phone' => '+14155550111'],
            'Elena Duarte' => ['company' => 'Linear', 'title' => 'Head of Sales', 'phone' => '+14155550112'],
            'Jonas Keller' => ['company' => 'Vercel', 'title' => 'Director of Platform Partnerships', 'phone' => '+14155550113'],
            'Amara Okafor' => ['company' => 'Vercel', 'title' => 'Sales Engineer', 'phone' => '+14155550114'],
            'Nina Alvarez' => ['company' => 'Slack', 'title' => 'Enterprise Account Director', 'phone' => '+14155550115'],
            'Theo Lindqvist' => ['company' => 'Canva', 'title' => 'Head of Business Development', 'phone' => '+14155550116'],
            'Yuki Nakamura' => ['company' => 'Canva', 'title' => 'Design Operations Manager', 'phone' => '+14155550117'],
            'Rachel Osei' => ['company' => 'Shopify', 'title' => 'Director of Merchant Success', 'phone' => '+14155550118'],
            'Daniel Kim' => ['company' => 'Shopify', 'title' => 'Partnerships Lead', 'phone' => '+14155550119'],
            'Sofia Grimaldi' => ['company' => 'Datadog', 'title' => 'Regional Sales Manager', 'phone' => '+14155550120'],
            'Marco Bianchi' => ['company' => 'Datadog', 'title' => 'Head of Observability', 'phone' => '+14155550121'],
            'Owen Bradshaw' => ['company' => 'Ramp', 'title' => 'Head of Finance Operations', 'phone' => '+14155550122'],
            'Lucia Ferreira' => ['company' => 'Ramp', 'title' => 'Account Executive', 'phone' => '+14155550123'],
            'Hugo Marchand' => ['company' => 'Retool', 'title' => 'Solutions Architect', 'phone' => '+14155550124'],
            'Mei Tanaka' => ['company' => 'Loom', 'title' => 'Customer Success Lead', 'phone' => '+14155550125'],
            'Anton Volkov' => ['company' => 'Miro', 'title' => 'Head of Workspace Strategy', 'phone' => '+14155550126'],
            'Clara Bennett' => ['company' => 'Miro', 'title' => 'Procurement Manager', 'phone' => '+14155550127'],
            'Isaac Mbeki' => ['company' => 'Webflow', 'title' => 'Web Platform Lead', 'phone' => '+14155550128'],
            'Fiona Doyle' => ['company' => 'Zapier', 'title' => 'Automation Program Manager', 'phone' => '+14155550129'],
            'Peter Nowak' => ['company' => 'Zapier', 'title' => 'Head of Integrations', 'phone' => '+14155550130'],
            'Sana Iqbal' => ['company' => 'Intercom', 'title' => 'Director of Support Operations', 'phone' => '+14155550131'],
            'Niall Sweeney' => ['company' => 'Intercom', 'title' => 'Revenue Operations Analyst', 'phone' => '+14155550132'],
            'Gabriel Ruiz' => ['company' => 'Airtable', 'title' => 'Data Platform Manager', 'phone' => '+14155550133'],
            'Helena Roth' => ['company' => 'Amplitude', 'title' => 'VP Product Analytics', 'phone' => '+14155550134'],
            'Tomas Bergstrom' => ['company' => 'Amplitude', 'title' => 'Enterprise Account Executive', 'phone' => '+14155550135'],
        ];
    }

    /**
     * @return array<string, array{company: string, contact: string, amount: int, stage: string, closes_in_days: int}>
     */
    private function opportunityFixtures(): array
    {
        return [
            'Stripe Billing Rollout' => ['company' => 'Stripe', 'contact' => 'Marcus Webb', 'amount' => 42000, 'stage' => 'Negotiation/Review', 'closes_in_days' => 14],
            'Stripe Partner Program' => ['company' => 'Stripe', 'contact' => 'Priya Raman', 'amount' => 18500, 'stage' => 'Prospecting', 'closes_in_days' => 45],
            'Linear Workflow Sync' => ['company' => 'Linear', 'contact' => 'Elena Duarte', 'amount' => 12000, 'stage' => 'Qualification', 'closes_in_days' => 21],
            'Vercel Edge Deployment' => ['company' => 'Vercel', 'contact' => 'Jonas Keller', 'amount' => 26000, 'stage' => 'Value Proposition', 'closes_in_days' => 30],
            'Slack Enterprise Grid' => ['company' => 'Slack', 'contact' => 'Nina Alvarez', 'amount' => 54000, 'stage' => 'Proposal/Price Quote', 'closes_in_days' => 10],
            'Canva Brand Kit Expansion' => ['company' => 'Canva', 'contact' => 'Theo Lindqvist', 'amount' => 9500, 'stage' => 'Prospecting', 'closes_in_days' => 60],
            'Shopify Merchant Analytics' => ['company' => 'Shopify', 'contact' => 'Rachel Osei', 'amount' => 78000, 'stage' => 'Id. Decision Makers', 'closes_in_days' => 35],
            'Datadog Monitoring Bundle' => ['company' => 'Datadog', 'contact' => 'Sofia Grimaldi', 'amount' => 33000, 'stage' => 'Needs Analysis', 'closes_in_days' => 28],
            'Ramp Spend Controls' => ['company' => 'Ramp', 'contact' => 'Owen Bradshaw', 'amount' => 21000, 'stage' => 'Closed Won', 'closes_in_days' => -5],
            'Retool Internal Tools' => ['company' => 'Retool', 'contact' => 'Hugo Marchand', 'amount' => 15500, 'stage' => 'Perception Analysis', 'closes_in_days' => 18],
            'Miro Workshop Licences' => ['company' => 'Miro', 'contact' => 'Anton Volkov', 'amount' => 8800, 'stage' => 'Closed Lost', 'closes_in_days' => -12],
            'Zapier Automation Tier' => ['company' => 'Zapier', 'contact' => 'Fiona Doyle', 'amount' => 16400, 'stage' => 'Negotiation/Review', 'closes_in_days' => 7],
            'Intercom Support Migration' => ['company' => 'Intercom', 'contact' => 'Sana Iqbal', 'amount' => 29500, 'stage' => 'Proposal/Price Quote', 'closes_in_days' => 24],
            'Amplitude Product Insights' => ['company' => 'Amplitude', 'contact' => 'Helena Roth', 'amount' => 47000, 'stage' => 'Qualification', 'closes_in_days' => 40],
        ];
    }

    /**
     * @return array<string, array{company: string, contact: string, opportunity: string, status: string, priority: string, due_in_days: int, assigned: bool, description: string}>
     */
    private function taskFixtures(): array
    {
        return [
            'Send Stripe billing quote' => ['company' => 'Stripe', 'contact' => 'Marcus Webb', 'opportunity' => 'Stripe Billing Rollout', 'status' => 'In progress', 'priority' => 'High', 'due_in_days' => -3, 'assigned' => true, 'description' => 'Finalise usage tiers and send the signed quote for the billing rollout.'],
            'Chase Shopify security review' => ['company' => 'Shopify', 'contact' => 'Rachel Osei', 'opportunity' => 'Shopify Merchant Analytics', 'status' => 'To do', 'priority' => 'High', 'due_in_days' => -1, 'assigned' => true, 'description' => 'Security team still owes us the completed questionnaire before procurement starts.'],
            'Collect Datadog usage baseline' => ['company' => 'Datadog', 'contact' => 'Sofia Grimaldi', 'opportunity' => 'Datadog Monitoring Bundle', 'status' => 'To do', 'priority' => 'Medium', 'due_in_days' => -4, 'assigned' => false, 'description' => 'Pull last quarter of host counts so the bundle is sized against real usage.'],
            'Prep Slack Enterprise Grid demo' => ['company' => 'Slack', 'contact' => 'Nina Alvarez', 'opportunity' => 'Slack Enterprise Grid', 'status' => 'To do', 'priority' => 'High', 'due_in_days' => 1, 'assigned' => true, 'description' => 'Tailor the demo around shared channels and their compliance requirements.'],
            'Draft Linear onboarding plan' => ['company' => 'Linear', 'contact' => 'Elena Duarte', 'opportunity' => 'Linear Workflow Sync', 'status' => 'In progress', 'priority' => 'Medium', 'due_in_days' => 3, 'assigned' => true, 'description' => 'Two-week rollout covering workflow mapping and admin training.'],
            'Review Vercel edge pricing' => ['company' => 'Vercel', 'contact' => 'Jonas Keller', 'opportunity' => 'Vercel Edge Deployment', 'status' => 'To do', 'priority' => 'Medium', 'due_in_days' => 5, 'assigned' => true, 'description' => 'Compare edge bandwidth tiers against their projected traffic.'],
            'Book Canva discovery call' => ['company' => 'Canva', 'contact' => 'Theo Lindqvist', 'opportunity' => 'Canva Brand Kit Expansion', 'status' => 'To do', 'priority' => 'Low', 'due_in_days' => 8, 'assigned' => false, 'description' => 'Introductory call to scope the brand kit expansion.'],
            'Share Ramp rollout checklist' => ['company' => 'Ramp', 'contact' => 'Owen Bradshaw', 'opportunity' => 'Ramp Spend Controls', 'status' => 'Done', 'priority' => 'Medium', 'due_in_days' => -6, 'assigned' => true, 'description' => 'Checklist handed to finance ops ahead of the spend controls launch.'],
            'Summarise Miro loss reasons' => ['company' => 'Miro', 'contact' => 'Anton Volkov', 'opportunity' => 'Miro Workshop Licences', 'status' => 'Done', 'priority' => 'Low', 'due_in_days' => -10, 'assigned' => true, 'description' => 'Budget was reallocated this quarter; revisit after their planning cycle.'],
            'Align Zapier legal terms' => ['company' => 'Zapier', 'contact' => 'Fiona Doyle', 'opportunity' => 'Zapier Automation Tier', 'status' => 'In progress', 'priority' => 'High', 'due_in_days' => 2, 'assigned' => true, 'description' => 'Data processing addendum is with legal for the automation tier.'],
            'Scope Intercom data migration' => ['company' => 'Intercom', 'contact' => 'Sana Iqbal', 'opportunity' => 'Intercom Support Migration', 'status' => 'To do', 'priority' => 'High', 'due_in_days' => 6, 'assigned' => true, 'description' => 'Estimate the conversation history volume before committing to a cutover date.'],
            'Send Amplitude case study' => ['company' => 'Amplitude', 'contact' => 'Helena Roth', 'opportunity' => 'Amplitude Product Insights', 'status' => 'To do', 'priority' => 'Low', 'due_in_days' => 12, 'assigned' => false, 'description' => 'Share the product analytics case study she asked for on the intro call.'],
            'Confirm Retool sandbox access' => ['company' => 'Retool', 'contact' => 'Hugo Marchand', 'opportunity' => 'Retool Internal Tools', 'status' => 'In progress', 'priority' => 'Medium', 'due_in_days' => 4, 'assigned' => true, 'description' => 'Their architect needs sandbox credentials before the technical review.'],
            'Follow up with Loom on renewal' => ['company' => 'Loom', 'contact' => 'Mei Tanaka', 'opportunity' => '', 'status' => 'To do', 'priority' => 'Medium', 'due_in_days' => 9, 'assigned' => true, 'description' => 'Renewal conversation opens next month; confirm the seat count first.'],
            'Introduce Airtable to the data team' => ['company' => 'Airtable', 'contact' => 'Gabriel Ruiz', 'opportunity' => '', 'status' => 'To do', 'priority' => 'Low', 'due_in_days' => 15, 'assigned' => false, 'description' => 'Warm introduction between their data platform manager and our engineers.'],
            'Recap Webflow platform review' => ['company' => 'Webflow', 'contact' => 'Isaac Mbeki', 'opportunity' => '', 'status' => 'Done', 'priority' => 'Low', 'due_in_days' => -8, 'assigned' => true, 'description' => 'Platform review went well; they will revisit budget next quarter.'],
        ];
    }

    /**
     * @return array<string, array{company: string, contact: string, opportunity: string, body: string}>
     */
    private function noteFixtures(): array
    {
        return [
            'Stripe billing requirements' => ['company' => 'Stripe', 'contact' => 'Marcus Webb', 'opportunity' => 'Stripe Billing Rollout', 'body' => 'Usage-based billing with monthly invoicing. Finance wants a single consolidated invoice per region, and revenue operations owns the rollout.'],
            'Slack procurement timeline' => ['company' => 'Slack', 'contact' => 'Nina Alvarez', 'opportunity' => 'Slack Enterprise Grid', 'body' => 'Procurement closes the quarter in three weeks. Security review runs in parallel, so the quote needs to land before the freeze.'],
            'Shopify security questionnaire' => ['company' => 'Shopify', 'contact' => 'Rachel Osei', 'opportunity' => '', 'body' => 'Standard questionnaire plus a penetration test summary. Their security lead reviews everything before merchant success can sign off.'],
            'Ramp rollout retrospective' => ['company' => 'Ramp', 'contact' => 'Owen Bradshaw', 'opportunity' => 'Ramp Spend Controls', 'body' => 'Spend controls went live on schedule. Finance ops asked for a quarterly check-in and flagged interest in the approvals module.'],
            'Miro deal loss debrief' => ['company' => 'Miro', 'contact' => 'Anton Volkov', 'opportunity' => 'Miro Workshop Licences', 'body' => 'Lost on budget, not on product. Workshop licences were cut in their planning cycle; revisit in the next fiscal year.'],
            'Zapier integration scope' => ['company' => 'Zapier', 'contact' => 'Fiona Doyle', 'opportunity' => 'Zapier Automation Tier', 'body' => 'Two-way sync for contacts and deals, with retries handled on their side. Legal is reviewing the data processing addendum.'],
            'Amplitude analytics goals' => ['company' => 'Amplitude', 'contact' => 'Helena Roth', 'opportunity' => 'Amplitude Product Insights', 'body' => 'They want activation and retention dashboards in the first month. Product analytics team of six, expanding next quarter.'],
        ];
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
        $field = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $team->getKey())
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->with(['options' => fn (Relation $query): Relation => $query->withoutGlobalScopes()])
            ->firstOrFail();

        throw_unless($field instanceof CustomField, RuntimeException::class, "Custom field {$entityType}.{$code} is missing from the reviewer workspace.");

        return $field;
    }
}
