<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

/**
 * Staff and customer sessions are isolated per host, so the link is a single-use
 * bearer handoff the app host consumes; it re-checks the `impersonate` gate itself.
 */
final class Impersonate
{
    private const int LINK_LIFETIME_SECONDS = 60;

    public static function user(): Action
    {
        return Action::make('impersonate')
            ->label('Impersonate')
            ->icon('heroicon-o-user-circle')
            ->color('warning')
            ->authorize('impersonate')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-user-circle')
            ->modalHeading('Start impersonating')
            ->modalDescription(fn (User $record): string => "You will be signed in as {$record->name} ({$record->email}). Everything you do is logged against your administrator account, and your sysadmin session stays open.")
            ->modalSubmitActionLabel('Start impersonating')
            ->action(fn (User $record) => redirect()->to(self::link($record)));
    }

    public static function workspaceOwner(): Action
    {
        return Action::make('impersonateOwner')
            ->label('Impersonate owner')
            ->icon('heroicon-o-user-circle')
            ->color('warning')
            ->authorize('impersonateOwner')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-user-circle')
            ->modalHeading('Impersonate workspace owner')
            ->modalDescription(fn (Workspace $record): string => "You will be signed in as {$record->owner?->name} ({$record->owner?->email}) and land in {$record->name}. Everything you do is logged against your administrator account.")
            ->modalSubmitActionLabel('Start impersonating')
            ->action(fn (Workspace $record) => redirect()->to(
                self::link($record->owner()->firstOrFail(), ['workspace' => $record->getKey()])
            ));
    }

    /**
     * The owner is never null here: impersonateOwner() refuses a workspace without one.
     *
     * @param  array<string, string>  $parameters
     */
    private static function link(User $target, array $parameters = []): string
    {
        $path = URL::temporarySignedRoute(
            'impersonation.start',
            now()->addSeconds(self::LINK_LIFETIME_SECONDS),
            [
                'user' => $target->getKey(),
                'administrator' => self::administrator()->getKey(),
                'nonce' => Str::random(40),
                ...$parameters,
            ],
            absolute: false,
        );

        return config('app.app_panel_domain')
            ? url()->getAppUrl($path)
            : url()->getPublicUrl($path);
    }

    private static function administrator(): SystemAdministrator
    {
        $administrator = auth('sysadmin')->user();

        abort_unless($administrator instanceof SystemAdministrator, 403);

        return $administrator;
    }
}
