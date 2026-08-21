<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\MakeFilamentUserCommand;
use App\Enums\Plan;
use App\Filament\CustomFields\DateFieldType;
use App\Filament\CustomFields\DateTimeFieldType;
use App\Http\Responses\LoginResponse;
use App\Listeners\Billing\SyncPlanOnStripeSubscriptionChange;
use App\Listeners\Email\NewSubscriberListener;
use App\Listeners\Email\RecordLoginTimestampListener;
use App\Listeners\Email\TeamCreatedTagListener;
use App\Listeners\Email\TeamMemberAddedListener;
use App\Listeners\Mcp\CopyTeamIdToAccessToken;
use App\Listeners\SeedTeamCreditBalanceListener;
use App\Livewire\FilamentNotifications;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\ActivityLog\Activity as ActivityModel;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldSection;
use App\Models\CustomFieldValue;
use App\Models\Export;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\Passport\AuthCode as McpAuthCode;
use App\Models\People;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\HostedWorkspaceAccess;
use App\Services\GitHubService;
use App\Support\ActivityLog\MergedActivityRenderer;
use App\Support\ActivityLog\RequestActivityBatch;
use App\Support\Markdown\TableAwareLeagueDriver;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Livewire\Notifications;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;
use Knuckles\Scribe\Scribe;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Relaticle\ActivityLog\Facades\Timeline;
use Relaticle\Chat\Support\ChatTelemetry;
use Relaticle\CustomFields\CustomFields;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\Ink\Filament\Resources\PostResource;
use Relaticle\Ink\Ink;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Spatie\Activitylog\Facades\Activity as ActivityLogger;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class, LoginResponse::class);
        $this->app->bind(\Filament\Actions\Exports\Models\Export::class, Export::class);

        // Ink registers its public routes from packageBooted(), which runs after every
        // provider's register(). Read the config key App\Features\Blog resolves from
        // rather than the Pennant facade: Pennant needs the `hash` service, which is not
        // bound this early. Same source of truth, so the flag stays the single switch.
        config(['ink.features.public_routes' => (bool) config('relaticle.features.blog', false)]);

        Cashier::useCustomerModel(Team::class);
        Cashier::keepPastDueSubscriptionsActive();

        // Cashier attaches signature verification only when the webhook secret
        // happens to be set, and also exposes an unauthenticated payment route
        // this app never links to. Register the webhook ourselves instead so
        // verification is unconditional — see routes/web.php.
        Cashier::ignoreRoutes();

        // One batch_uuid per request/job, lazily generated and forgotten between
        // them — the key the activity timeline groups a single save's rows on.
        $this->app->scoped(RequestActivityBatch::class);

        // Spatie's LeagueDriver never registers TableConverter, so <table>
        // markup collapses to a run-on line in the markdown-response channel.
        // Our provider registers after the package's, so this binding wins.
        $this->app->singleton(
            'markdown-response.driver.league',
            fn (): TableAwareLeagueDriver => new TableAwareLeagueDriver(
                config('markdown-response.driver_options.league.options', []),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeFilamentUserCommand::class,
            ]);
        }

        Event::listen(Login::class, RecordLoginTimestampListener::class);
        Event::listen(Verified::class, NewSubscriberListener::class);
        Event::listen(TeamMemberAdded::class, TeamMemberAddedListener::class);
        Event::listen(TeamCreated::class, TeamCreatedTagListener::class);
        Event::listen(TeamCreated::class, SeedTeamCreditBalanceListener::class);

        Event::listen(WebhookHandled::class, SyncPlanOnStripeSubscriptionChange::class);

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Passport::useAuthCodeModel(McpAuthCode::class);
        Event::listen(AccessTokenCreated::class, CopyTeamIdToAccessToken::class);

        // Connectors are long-lived but must not be immortal: a user who revokes one from
        // the Access Tokens page should not be outlived by a year-long bearer token.
        Passport::tokensExpireIn(now()->addDays(30));
        Passport::refreshTokensExpireIn(now()->addDays(90));

        Passport::authorizationView(function (array $parameters) {
            $user = $parameters['user'] ?? null;

            $teams = $user instanceof User ? $user->allTeams() : collect();
            $access = resolve(HostedWorkspaceAccess::class);

            // Re-read the teams with their subscriptions eager-loaded: isPaused() reaches
            // for $team->subscription(), and allTeams() hydrates more than one row, which
            // is exactly when strict lazy loading throws.
            /** @var list<string> $pausedTeamIds */
            $pausedTeamIds = Team::query()
                ->whereIn('id', $teams->pluck('id')->all())
                ->with('subscriptions')
                ->get()
                ->filter(fn (Team $team): bool => $access->isPaused($team))
                ->map(fn (Team $team): string => (string) $team->getKey())
                ->values()
                ->all();

            $parameters['teams'] = $teams;
            $parameters['pausedTeamIds'] = $pausedTeamIds;

            // Never preselect a workspace the connector could not use — the user would
            // approve a token that answers 402 on every call.
            $currentTeamId = $user?->currentTeam?->getKey();

            $parameters['selectedTeamId'] = is_string($currentTeamId) && ! in_array($currentTeamId, $pausedTeamIds, true)
                ? $currentTeamId
                : $teams->first(fn (Team $team): bool => ! in_array((string) $team->getKey(), $pausedTeamIds, true))?->getKey();

            return response()->view('mcp.authorize', $parameters);
        });

        $this->configurePolicies();
        $this->configureModels();
        $this->configureFilament();
        $this->configureGitHubStars();
        $this->configureLivewire();
        $this->configureRateLimiting();
        $this->configureScribe();

        $this->configureActivityLog();
        $this->configureBlog();
    }

    /**
     * The blog admin lives in the sysadmin panel, which has no tenancy, so only a
     * signed-in system administrator gets an edit link on a draft preview. Which
     * panel and guard own the admin is ours to decide, not the package's.
     */
    private function configureBlog(): void
    {
        Ink::resolvePreviewEditUrlUsing(fn (Post $post): ?string => auth('sysadmin')->check()
            ? PostResource::getUrl('edit', ['record' => $post], panel: 'sysadmin')
            : null);

        // MCP callers authenticate as sysadmin accounts, but posts belong to User
        // authors. Match by email; no match is a loud tool error, never a fallback.
        Ink::resolveAuthorUsing(function (Authenticatable $caller): ?User {
            if ($caller instanceof User) {
                return $caller;
            }

            if (! $caller instanceof Model) {
                return null;
            }

            $email = $caller->getAttribute('email');

            if (! is_string($email)) {
                return null;
            }

            return User::query()->where('email', $email)->first();
        });

        // HasSEO creates a row per post but never removes it. A soft delete should
        // keep it — the post can come back — but a force delete from the panel
        // would otherwise leave the seo row behind for good.
        Post::forceDeleted(fn (Post $post) => $post->seo()->delete());
    }

    /**
     * Stamp every activity row written during one request/job with that request's
     * shared batch_uuid, and render a same-save group (native columns + custom
     * fields) as a single merged timeline entry.
     */
    private function configureActivityLog(): void
    {
        ActivityLogger::beforeLogging(function (ActivityModel $activity): void {
            if (blank($activity->getAttribute('batch_uuid'))) {
                $activity->setAttribute('batch_uuid', $this->app->make(RequestActivityBatch::class)->id());
            }
        });

        Timeline::registerRenderer('merged-activity', MergedActivityRenderer::class);
    }

    private function configurePolicies(): void
    {
        Gate::guessPolicyNamesUsing(function (string $modelClass): ?string {
            try {
                $currentPanelId = Filament::getCurrentPanel()?->getId();

                if ($currentPanelId === 'sysadmin') {
                    $modelName = class_basename($modelClass);
                    $systemAdminPolicy = "Relaticle\\SystemAdmin\\Policies\\{$modelName}Policy";

                    // Return SystemAdmin policy if it exists
                    if (class_exists($systemAdminPolicy)) {
                        return $systemAdminPolicy;
                    }
                }
            } catch (\Exception) {
                // Fallback for non-Filament contexts
            }

            // Use Laravel's default policy discovery logic
            return $this->getDefaultLaravelPolicyName($modelClass);
        });
    }

    private function getDefaultLaravelPolicyName(string $modelClass): ?string
    {
        // Replicate Laravel's default policy discovery logic from Gate.php:723-736
        $classDirname = str_replace('/', '\\', dirname(str_replace('\\', '/', $modelClass)));
        $classDirnameSegments = explode('\\', $classDirname);

        $candidates = collect();
        // Generate all possible policy paths
        $counter = count($classDirnameSegments);

        // Generate all possible policy paths
        for ($index = 0; $index < $counter; $index++) {
            $classDirname = implode('\\', array_slice($classDirnameSegments, 0, $index));
            $candidates->push($classDirname.'\\Policies\\'.class_basename($modelClass).'Policy');
        }

        // Add Models-specific paths if the model is in a Models directory
        if (str_contains($classDirname, '\\Models\\')) {
            $candidates = $candidates
                ->concat([str_replace('\\Models\\', '\\Policies\\', $classDirname).'\\'.class_basename($modelClass).'Policy'])
                ->concat([str_replace('\\Models\\', '\\Models\\Policies\\', $classDirname).'\\'.class_basename($modelClass).'Policy']);
        }

        // Return the first existing class, or fallback
        $existingPolicy = $candidates->reverse()->first(fn (string $class): bool => class_exists($class));

        return $existingPolicy ?: $classDirname.'\\Policies\\'.class_basename($modelClass).'Policy';
    }

    /**
     * Configure custom Livewire components.
     */
    private function configureLivewire(): void
    {
        // Route the panel's notifications component to the subclass that
        // tolerates junk in stale client payloads (Sentry #120218486). Both
        // keys are needed: the raw FQCN entry canonicalizes class-based
        // renders (and old snapshots naming the FQCN) onto the dotted name,
        // and the dotted entry carries the mapping the resolver reads.
        Livewire::component('filament.livewire.notifications', FilamentNotifications::class);
        Livewire::component(Notifications::class, FilamentNotifications::class);
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): array {
            /** @var User|null $user */
            $user = $request->user();
            $tokenId = $user?->currentAccessToken()?->getKey();
            $teamId = $user?->currentTeam?->getKey();
            $key = $tokenId ?: $request->ip();

            $limits = [
                Limit::perMinute(600)->by('team:'.($teamId ?? $request->ip())),
            ];

            if ($request->isMethod('GET')) {
                $limits[] = Limit::perMinute(300)->by("token:{$key}:read");
            } else {
                $limits[] = Limit::perMinute(60)->by("token:{$key}:write");
            }

            return $limits;
        });

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for(
            'mcp-oauth',
            fn (Request $request) => Limit::perMinute(20)->by($request->ip()),
        );

        RateLimiter::for('chat-send', function (Request $request) {
            /** @var User|null $user */
            $user = $request->user();
            $team = $user?->currentTeam;

            if ($team === null) {
                return Limit::perMinute(Plan::default()->rateLimit())->by('chat-anon');
            }

            return Limit::perMinute($team->plan->rateLimit())
                ->by($team->getKey())
                ->response(function (Request $request, array $headers) use ($team) {
                    ChatTelemetry::rateLimited(
                        teamId: (string) $team->getKey(),
                        plan: $team->plan->value,
                    );

                    $seconds = (int) ($headers['Retry-After'] ?? 0);

                    return response()->json([
                        'error' => 'rate_limited',
                        'message' => "You're sending messages quickly. You can send again in {$seconds} seconds.",
                        'retry_after_seconds' => $seconds,
                        'plan' => $team->plan->value,
                    ], 429, $headers);
                });
        });
    }

    private function configureScribe(): void
    {
        if (! class_exists(Scribe::class)) {
            return;
        }

        $makeScribeUser = function (): User {
            $user = new User;
            $user->forceFill(['id' => 'scribe-user-id', 'name' => 'Scribe User', 'email' => 'scribe@example.com']);

            $team = new Team;
            $team->forceFill(['id' => 'scribe-team-id', 'name' => 'Scribe Team', 'user_id' => $user->id, 'personal_team' => true]);
            $team->setRelation('owner', $user);
            $team->setRelation('users', collect());

            $user->forceFill(['current_team_id' => $team->id]);
            $user->setRelation('currentTeam', $team);

            return $user;
        };

        Scribe::bootstrap(function (): void {
            config()->set('scribe.generating', true);
        });

        Scribe::instantiateFormRequestUsing(function (string $className) use ($makeScribeUser): FormRequest {
            /** @var FormRequest $formRequest */
            $formRequest = new $className;
            $formRequest->setUserResolver(fn (): User => $makeScribeUser());

            return $formRequest;
        });

    }

    /**
     * Configure the models for the application.
     */
    private function configureModels(): void
    {
        Model::unguard();
        Model::preventLazyLoading(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'team' => Team::class,
            'user' => User::class,
            'people' => People::class,
            'company' => Company::class,
            'opportunity' => Opportunity::class,
            'task' => Task::class,
            'note' => Note::class,
            'system_administrator' => SystemAdministrator::class,
            'custom_field' => CustomField::class,
            'blog_post' => Post::class,
            'blog_category' => Category::class,
        ]);

        // Use custom models for custom-fields package
        CustomFields::useCustomFieldModel(CustomField::class);
        CustomFields::useSectionModel(CustomFieldSection::class);
        CustomFields::useOptionModel(CustomFieldOption::class);
        CustomFields::useValueModel(CustomFieldValue::class);

        // Replaces the package's definitions so custom-field dates read the same as the
        // native columns beside them: `date-time` swaps the table column, which otherwise
        // renders stored UTC to every viewer (App\Filament\CustomFields\DateTimeColumn),
        // and both swap the infolist entry, which otherwise hardcodes `Y-m-d H:i:s` on
        // record pages (App\Filament\CustomFields\DateTimeEntry).
        //
        // `date` gets the entry only. A bare date has no time of day, so converting one
        // would move it a day for every viewer west of UTC.
        CustomFieldsType::register([
            'date-time' => DateTimeFieldType::class,
            'date' => DateFieldType::class,
        ]);

        $this->configureCustomFieldSchemaInvalidation();
    }

    /**
     * The AI list tools memoise which custom fields are filterable, per tenant and
     * entity, for a minute. Hooking the model rather than the actions keeps the
     * Filament management page — which writes definitions directly — from leaving
     * the assistant insisting a field the user just added does not exist.
     */
    private function configureCustomFieldSchemaInvalidation(): void
    {
        $invalidate = function (CustomField $field): void {
            $tenantId = $field->getAttribute('tenant_id');
            $entityType = $field->getAttribute('entity_type');

            if ((is_string($tenantId) || is_int($tenantId)) && is_string($entityType)) {
                CustomFieldFilterSchema::forget($tenantId, $entityType);
            }
        };

        CustomField::saved($invalidate);
        CustomField::deleted($invalidate);
    }

    /**
     * Configure Filament.
     */
    private function configureFilament(): void
    {
        $slideOverActions = ['create', 'edit', 'view'];

        Action::configureUsing(function (Action $action) use ($slideOverActions): Action {
            if (in_array($action->getName(), $slideOverActions)) {
                return $action->slideOver();
            }

            return $action;
        });

        /**
         * Datetimes are stored in UTC; every panel renders and accepts them in the
         * signed-in account's chosen zone. Read by both table/infolist output and
         * DateTimePicker input, so one closure keeps display and entry symmetrical.
         *
         * TimezoneManager is a single global slot, so this must stay the only writer
         * — a second FilamentTimezone::set() anywhere would silently replace it, and
         * which one survived would depend on service provider boot order. It lives
         * here rather than in a panel provider for the same reason: the resolution
         * spans every panel.
         *
         * The account is read through the current panel's own guard and by attribute
         * rather than by class, so App\Models\User and SystemAdministrator are both
         * served without this layer naming the sysadmin package. Returning null falls
         * back to config('app.timezone'), which covers an unset zone, a zone that is
         * no longer a valid identifier, an account type that has no zone at all, and
         * any request outside a panel.
         */
        FilamentTimezone::set(function (): ?string {
            if (Filament::getCurrentPanel() === null) {
                return null;
            }

            $timezone = Filament::auth()->user()?->getAttribute('timezone');

            if (! is_string($timezone)) {
                return null;
            }

            return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : null;
        });
    }

    /**
     * Configure GitHub stars count.
     */
    private function configureGitHubStars(): void
    {
        Facades\View::composer(['components.layout.header', 'home.partials.hero'], function (View $view): void {
            $gitHubService = resolve(GitHubService::class);
            $starsCount = $gitHubService->getStarsCount();
            $formattedStarsCount = $gitHubService->getFormattedStarsCount();

            $view->with([
                'githubStars' => $starsCount,
                'formattedGithubStars' => $formattedStarsCount,
            ]);
        });
    }
}
