<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Illuminate\Support\Facades\URL;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Policies\UserPolicy;
use Relaticle\SystemAdmin\Policies\WorkspacePolicy;

/**
 * The link is a short-lived signed GET so the panel can redirect straight into it.
 * The app-side controller re-checks the `impersonate` gate; the signature only
 * protects the parameters, it does not stand in for authorization.
 */
final class Impersonate
{
    private const int LINK_LIFETIME_MINUTES = 5;

    public static function user(): Action
    {
        return Action::make('impersonate')
            ->label('Impersonate')
            ->icon('heroicon-o-user-circle')
            ->color('warning')
            ->authorize(fn (User $record): bool => self::allowed()
                && resolve(UserPolicy::class)->impersonate(self::administrator()))
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
            ->authorize(fn (Workspace $record): bool => self::allowed()
                && resolve(WorkspacePolicy::class)->impersonateOwner(self::administrator(), $record))
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
        return URL::temporarySignedRoute(
            'impersonation.start',
            now()->addMinutes(self::LINK_LIFETIME_MINUTES),
            ['user' => $target->getKey(), ...$parameters]
        );
    }

    private static function allowed(): bool
    {
        return auth('sysadmin')->user() instanceof SystemAdministrator;
    }

    private static function administrator(): SystemAdministrator
    {
        $administrator = auth('sysadmin')->user();

        abort_unless($administrator instanceof SystemAdministrator, 403);

        return $administrator;
    }
}
