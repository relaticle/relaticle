<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\MakeFilamentUserCommand;
use App\Enums\Plan;
use App\Http\Responses\LoginResponse;
use App\Listeners\Email\NewSubscriberListener;
use App\Listeners\Email\RecordLoginTimestampListener;
use App\Listeners\Email\TeamCreatedTagListener;
use App\Listeners\Email\TeamMemberAddedListener;
use App\Listeners\SeedTeamCreditBalanceListener;
use App\Livewire\FilamentNotifications;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldSection;
use App\Models\CustomFieldValue;
use App\Models\Export;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\GitHubService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Livewire\Notifications;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
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
use Laravel\Ai\AiManager;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Relaticle\Chat\Support\ChatTelemetry;
use Relaticle\CustomFields\CustomFields;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class, LoginResponse::class);
        $this->app->bind(\Filament\Actions\Exports\Models\Export::class, Export::class);

        $this->app->scoped(AiManager::class, fn (Application $app): \App\Ai\AiManager => new \App\Ai\AiManager($app));
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

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configurePolicies();
        $this->configureModels();
        $this->configureFilament();
        $this->configureGitHubStars();
        $this->configureLivewire();
        $this->configureRateLimiting();
        $this->configureScribe();
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
        ]);

        // Use custom models for custom-fields package
        CustomFields::useCustomFieldModel(CustomField::class);
        CustomFields::useSectionModel(CustomFieldSection::class);
        CustomFields::useOptionModel(CustomFieldOption::class);
        CustomFields::useValueModel(CustomFieldValue::class);
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
