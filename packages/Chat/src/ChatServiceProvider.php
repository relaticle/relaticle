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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Relaticle\Chat\Commands\ExpirePendingActionsCommand;
use Relaticle\Chat\Commands\ResetCreditsCommand;
use Relaticle\Chat\Livewire\App\Chat\ChatAllChatsPanel;
use Relaticle\Chat\Livewire\App\Chat\ChatSidebarNav;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;
use Relaticle\Chat\Livewire\Chat\ChatInterface;

final class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chat.php', 'chat');
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerRoutes();
        $this->registerChannels();
        $this->registerViews();
        $this->registerLivewireComponents();
        $this->registerMigrations();
        $this->registerRenderHooks();
        $this->registerInsightsCacheInvalidation();
    }

    private function registerCommands(): void
    {
        $this->commands([
            ExpirePendingActionsCommand::class,
            ResetCreditsCommand::class,
        ]);
    }

    private function registerRoutes(): void
    {
        Route::middleware('web')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/chat.php');
            });
    }

    private function registerChannels(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/channels.php');
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'chat');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'chat');
    }

    private function registerLivewireComponents(): void
    {
        Livewire::component('chat.chat-interface', ChatInterface::class);
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

        Company::saved($invalidate);
        Company::deleted($invalidate);
        People::saved($invalidate);
        People::deleted($invalidate);
        Opportunity::saved($invalidate);
        Opportunity::deleted($invalidate);
        Task::saved($invalidate);
        Task::deleted($invalidate);
        Note::saved($invalidate);
        Note::deleted($invalidate);
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
