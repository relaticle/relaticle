<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

trait RedirectsToGrantSend
{
    /**
     * Re-run OAuth when the user has a mailbox that cannot send from Relaticle.
     */
    public function grantSendPermissionAction(): Action
    {
        return Action::make('grantSendPermission')
            ->label(__('filament/emails/composer.actions.grant_send.label'))
            ->color('primary')
            ->modalHeading(function (): string {
                $email = $this->mailboxMissingSend()?->email_address;

                return filled($email)
                    ? __('filament/emails/composer.grant_send.heading', ['email' => $email])
                    : __('filament/emails/composer.grant_send.heading_generic');
            })
            ->modalDescription(__('filament/emails/composer.grant_send.description'))
            ->modalSubmitActionLabel(__('filament/emails/composer.actions.grant_send.label'))
            ->action(function (): void {
                $this->redirectToGrantSend();
            });
    }

    private function redirectToGrantSend(?ConnectedAccount $account = null): void
    {
        $account ??= $this->mailboxMissingSend();

        if (! $account instanceof ConnectedAccount) {
            return;
        }

        $this->redirect(route('email-accounts.redirect', [
            'provider' => $account->provider->value,
        ]));
    }

    private function mailboxMissingSend(): ?ConnectedAccount
    {
        return $this->connectedMailboxes()
            ->first(fn (ConnectedAccount $account): bool => ! $account->hasSend());
    }

    private function sendableMailbox(): ?ConnectedAccount
    {
        return $this->connectedMailboxes()
            ->first(fn (ConnectedAccount $account): bool => $account->hasSend());
    }

    /**
     * @return array<string, string>
     */
    private function sendableAccountOptions(): array
    {
        return $this->connectedMailboxes()
            ->filter(fn (ConnectedAccount $account): bool => $account->hasSend())
            ->mapWithKeys(fn (ConnectedAccount $account): array => [$account->getKey() => $account->label])
            ->all();
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function connectedMailboxes(): Collection
    {
        $user = auth()->user();
        $team = filament()->getTenant();

        if (! $user instanceof User || ! $team instanceof Team) {
            return collect();
        }

        return ConnectedAccount::query()
            ->ownedBy($user, $team)
            ->connected()
            ->defaultFirst()
            ->get();
    }
}
