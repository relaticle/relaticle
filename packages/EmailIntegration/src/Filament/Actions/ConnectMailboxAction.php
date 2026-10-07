<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Actions;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;

final class ConnectMailboxAction extends Action
{
    private EmailProvider $provider = EmailProvider::GMAIL;

    public static function getDefaultName(): string
    {
        return 'connectMailbox';
    }

    public static function microsoft(): self
    {
        $action = self::make('connectAzure');
        $action->provider = EmailProvider::AZURE;

        return $action;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __("filament/pages/email-accounts.actions.connect_{$this->provider->value}"))
            ->icon(fn (): string => $this->provider->getIcon())
            ->color('gray')
            ->outlined()
            ->visible(fn (): bool => $this->provider->isConfigured())
            ->url(fn (): ?string => ($workspace = $this->workspace()) instanceof Workspace
                ? MailboxOAuthWorkspace::redirectUrl($this->provider->value, $workspace, Livewire::originalUrl())
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
