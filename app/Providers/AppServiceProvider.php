<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldSection;
use App\Models\CustomFieldValue;
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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;
use Knuckles\Scribe\Scribe;
use Laravel\Sanctum\Sanctum;
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
        $this->app->bind(\Filament\Actions\Exports\Models\Export::class, \App\Models\Export::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
        // Custom Livewire components can be registered here
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }

    private function configureScribe(): void
    {
        if (! class_exists(Scribe::class) || $this->app->environment('production')) {
            return;
        }

        Scribe::beforeResponseCall(function (\Symfony\Component\HttpFoundation\Request $request): void {
            $user = User::factory()->withPersonalTeam()->create();
            $user->switchTeam($user->personalTeam());
            Sanctum::actingAs($user, ['*']);
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
        // Share GitHub stars count with the header component
        Facades\View::composer('components.layout.header', function (View $view): void {
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
