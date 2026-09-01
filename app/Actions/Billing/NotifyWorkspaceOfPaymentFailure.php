<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Filament\Pages\Billing;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Tell the workspace owner that a renewal charge failed.
 *
 * The owner alone, because the billing page's portal button is owner-only:
 * anyone else would get an alarm about something they cannot act on.
 */
final readonly class NotifyWorkspaceOfPaymentFailure
{
    public function execute(Team $team): void
    {
        $owner = $team->owner()->first();

        if (! $owner instanceof User) {
            return;
        }

        Notification::make()
            ->title(__('billing.payment_failed.notification_title', ['workspace' => $team->name]))
            ->body(__('billing.payment_failed.notification_body'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('danger')
            ->actions([
                Action::make('billing')
                    ->button()
                    ->label(__('billing.payment_failed.notification_action'))
                    // A webhook binds no Filament tenant, so the panel and tenant
                    // have to be named or getUrl() resolves against neither.
                    ->url(Billing::getUrl(panel: 'app', tenant: $team))
                    ->markAsRead(),
            ])
            ->sendToDatabase($owner);
    }
}
