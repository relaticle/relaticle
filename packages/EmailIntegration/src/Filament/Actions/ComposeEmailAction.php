<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Actions;

use App\Features\EmailIntegration;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

final class ComposeEmailAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'composeEmail';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament/concerns/email-compose.actions.compose_email.label'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->visible(fn (Model $record): bool => Feature::active(EmailIntegration::class)
                && ! resolve(EmailVisibilityService::class)->hidesRecordMailbox($record))
            ->url(fn (): ?string => $this->hasConnectedMailbox() ? null : EmailAccountsPage::getUrl())
            ->dispatch('composer:open');
    }

    private function hasConnectedMailbox(): bool
    {
        $user = auth()->user();
        $workspace = filament()->getTenant();

        return $user instanceof User
            && $workspace instanceof Workspace
            && ConnectedAccount::hasConnectedFor($user, $workspace);
    }
}
