<?php

declare(strict_types=1);

namespace Relaticle\Chat;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Livewire\Livewire;
use Relaticle\Chat\Commands\ChatModelsCommand;
use Relaticle\Chat\Commands\ExpirePendingActionsCommand;
use Relaticle\Chat\Commands\ReleaseOrphanedReservationsCommand;
use Relaticle\Chat\Commands\ResetCreditsCommand;
use Relaticle\Chat\Livewire\App\Chat\ChatAllChatsPanel;
use Relaticle\Chat\Livewire\App\Chat\ChatSidebarNav;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;
use Relaticle\Chat\Support\AgentRunTelemetry;

final class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chat.php', 'chat');

        $this->app->singleton(ModelRegistry::class);

        $this->registerRoutes();

        // Replace laravel/ai's store so superseded (regenerated/edited-away)
        // turns disappear from the agent's history, not just the UI.
        $this->app->singleton(ConversationStore::class, fn (): SupersededAwareConversationStore => new SupersededAwareConversationStore(
            config('ai.conversations.connection'),
        ));
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerChannels();
        $this->registerViews();
        $this->registerLivewireComponents();
        $this->registerMigrations();
        $this->registerRenderHooks();
        $this->registerInsightsCacheInvalidation();
        $this->registerRunTelemetry();
    }

    /**
     * Correlate a failed turn to the step or tool that killed it, which the
     * job-level failure hook cannot see.
     */
    private function registerRunTelemetry(): void
    {
        Event::listen(
            [AgentFailed::class, StepFailed::class, ToolFailed::class],
            AgentRunTelemetry::class,
        );
    }

    private function registerCommands(): void
    {
        $this->commands([
            ChatModelsCommand::class,
            ExpirePendingActionsCommand::class,
            ReleaseOrphanedReservationsCommand::class,
            ResetCreditsCommand::class,
        ]);
    }

    /**
     * Registered from register(), not boot(), and pinned to the app panel's own
     * domain when there is one. Both halves are load-bearing in production,
     * where the panel is subdomain-routed (APP_PANEL_DOMAIN) and its tenant
     * routes lose the "/app" prefix: `{tenant:slug}/{resource}/{record}` then
     * reads `/r/people/{id}` as tenant "r" plus the People resource, and every
     * chip in a transcript 404s.
     *
     * - RouteCollection::get() returns every domain route ahead of every
     *   domainless one, so an unqualified chat route loses to the panel no
     *   matter which provider registered first.
     * - Filament registers panel routes from its own vendor provider's boot(),
     *   which runs before every app provider's boot(), so a chat route added
     *   during boot is always the later of the two.
     *
     * Both "r" and "chat" are reserved team slugs, so no legitimate tenant URL
     * can live under either prefix and winning the match here is correct.
     * tests/Feature/Routing/AppPanelRoutingTest.php pins both routing modes.
     */
    private function registerRoutes(): void
    {
        $route = Route::middleware('web');

        if (is_string($domain = config('app.app_panel_domain')) && $domain !== '') {
            $route->domain($domain);
        }

        $route->group(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/chat.php');
        });
    }

    private function registerChannels(): void
    {
        require __DIR__.'/../routes/channels.php';
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'chat');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'chat');
    }

    private function registerLivewireComponents(): void
    {
        Livewire::component('chat.chat-interface', ChatInterface::class);
        Livewire::component('chat.proposal-card', ProposalCard::class);
        Livewire::component('app.chat.chat-side-panel', ChatSidePanel::class);
        Livewire::component('app.chat.chat-sidebar-nav', ChatSidebarNav::class);
        Livewire::component('app.chat.chat-all-chats-panel', ChatAllChatsPanel::class);
    }

    private function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function registerInsightsCacheInvalidation(): void
    {
        $invalidate = function (Model $model): void {
            $teamId = $model->getAttribute('team_id');
            if (is_string($teamId) || is_int($teamId)) {
                Cache::forget("crm_insights_{$teamId}");
            }
        };

        foreach ([Company::class, People::class, Opportunity::class, Task::class, Note::class] as $model) {
            $model::saved($invalidate);
            $model::deleted($invalidate);
        }
    }

    private function registerRenderHooks(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => Blade::render("@vite(['resources/js/echo.js', 'packages/Chat/resources/js/chat.js'])"),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_NAV_END,
            fn (): View|Factory => view('chat::filament.app.chat-sidebar-nav-hook'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
            fn (): View|Factory => view('chat::filament.app.chat-topbar-toggle-hook'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): View|Factory => view('chat::filament.app.chat-side-panel-hook'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): View|Factory => view('chat::filament.app.chat-all-chats-panel-hook'),
        );
    }
}
