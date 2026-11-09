<?php

declare(strict_types=1);

namespace Relaticle\Chat;

use App\Enums\CrmEntity;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use Closure;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Js;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Livewire\Livewire;
use Relaticle\Chat\Commands\ChatModelsCommand;
use Relaticle\Chat\Commands\ExpirePendingActionsCommand;
use Relaticle\Chat\Commands\PurgeUnsentAttachmentsCommand;
use Relaticle\Chat\Commands\ReleaseOrphanedReservationsCommand;
use Relaticle\Chat\Commands\ResetCreditsCommand;
use Relaticle\Chat\Livewire\App\Chat\ChatAllChatsPanel;
use Relaticle\Chat\Livewire\App\Chat\ChatSidebarNav;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Settings\ChatSettings;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;
use Relaticle\Chat\Support\AgentRunTelemetry;
use Relaticle\Chat\Support\RecordChipRenderer;

final class ChatServiceProvider extends ServiceProvider
{
    private const string CLEAR_DRAFTS_COOKIE = 'chat_clear_drafts';

    private const int CLEAR_DRAFTS_COOKIE_MINUTES = 60 * 24;

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
        Relation::morphMap(['agent_conversation' => AgentConversation::class]);

        $this->applyStoredSettings();
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
     * Push the runtime-editable model catalog over the config seed defaults.
     *
     * Guarded because the settings table does not exist on a fresh install's
     * first `migrate`, nor while the app boots to run that migration; until then
     * the config values in chat.php simply stay in force. Runs before
     * ModelRegistry can be resolved (it is a singleton that reads config once in
     * its constructor), which is why this is the first line of boot().
     */
    private function applyStoredSettings(): void
    {
        rescue(fn () => config(resolve(ChatSettings::class)->toConfig()), report: false);
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
            PurgeUnsentAttachmentsCommand::class,
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
     * Both "r" and "chat" are reserved workspace slugs, so no legitimate tenant URL
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
            $workspaceId = $model->getAttribute('workspace_id');
            if (is_string($workspaceId) || is_int($workspaceId)) {
                Cache::forget("crm_insights_{$workspaceId}");
            }
        };

        foreach ([Company::class, People::class, Opportunity::class, Task::class, Note::class] as $model) {
            $model::saved($invalidate);
            $model::deleted($invalidate);
        }
    }

    /**
     * Hands the client half of the chip renderer the same icon set the server
     * half uses. Without this the path data would live twice, once in PHP and
     * once in chat.js, and a new record type could chip on reload while a
     * streamed reply showed a plain link.
     */
    private function recordChipIconScript(): string
    {
        $icons = RecordChipRenderer::icons();

        return sprintf(
            '<script>window.RECORD_CHIP_ICONS = %s; window.PROPOSAL_ICONS = %s;</script>',
            Js::from(Arr::only($icons, array_column(CrmEntity::cases(), 'value')))->toHtml(),
            Js::from($icons)->toHtml(),
        );
    }

    private function registerRenderHooks(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            $this->inAppPanel(fn (): string => $this->recordChipIconScript()
                .Blade::render("@vite(['packages/Chat/resources/js/chat.js'])")),
        );

        // A session that merely expired keeps its drafts: the composer restores them after sign-in.
        Event::listen(Logout::class, static function (): void {
            Cookie::queue(self::CLEAR_DRAFTS_COOKIE, '1', self::CLEAR_DRAFTS_COOKIE_MINUTES);
        });

        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
            $this->inAppPanel(function (): View|Factory|string {
                if (request()->cookie(self::CLEAR_DRAFTS_COOKIE) === null) {
                    return '';
                }

                Cookie::queue(Cookie::forget(self::CLEAR_DRAFTS_COOKIE));

                return view('chat::filament.app.clear-drafts-hook');
            }),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_NAV_END,
            $this->inAppPanel(fn (): View|Factory => view('chat::filament.app.chat-sidebar-nav-hook')),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
            $this->inAppPanel(fn (): View|Factory => view('chat::filament.app.chat-topbar-toggle-hook')),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            $this->inAppPanel(fn (): View|Factory => view('chat::filament.app.chat-side-panel-hook')),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            $this->inAppPanel(fn (): View|Factory => view('chat::filament.app.chat-all-chats-panel-hook')),
        );
    }

    /**
     * @param  Closure(): (View|Factory|string)  $render
     * @return Closure(): (View|Factory|string)
     */
    private function inAppPanel(Closure $render): Closure
    {
        return fn (): View|Factory|string => Filament::getCurrentPanel()?->getId() === 'app' ? $render() : '';
    }
}
