<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Actions;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;

final class ConnectMailboxAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'connectMailbox';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament/pages/email-accounts.actions.connect_gmail'))
            ->icon('icon-google')
            ->color('gray')
            ->outlined()
            ->url(fn (): ?string => ($workspace = $this->workspace()) instanceof Workspace
                ? MailboxOAuthWorkspace::redirectUrl('gmail', $workspace, Livewire::originalUrl())
                : null);
    }

    private function workspace(): ?Workspace
    {
        $tenant = filament()->getTenant();

        if ($tenant instanceof Workspace) {
            return $tenant;
        }

        $user = auth()->user();

        return $user instanceof User ? $user->currentWorkspace : null;
    }
}
