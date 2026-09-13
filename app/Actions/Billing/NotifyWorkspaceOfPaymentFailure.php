<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Filament\Pages\Billing;
use App\Models\User;
use App\Models\Workspace;
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
    public function execute(Workspace $workspace): void
    {
        $owner = $workspace->owner()->first();

        if (! $owner instanceof User) {
            return;
        }

        Notification::make()
            ->title(__('billing.payment_failed.notification_title', ['workspace' => $workspace->name]))
            ->body($workspace->plan === Plan::Enterprise
                ? __('billing.enterprise.previous_subscription_past_due')
                : __('billing.payment_failed.notification_body'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('danger')
            ->actions([
                Action::make('billing')
                    ->button()
                    ->label(__('billing.payment_failed.notification_action'))
                    // A webhook binds no Filament tenant, so the panel and tenant
                    // have to be named or getUrl() resolves against neither.
                    ->url(Billing::getUrl(panel: 'app', tenant: $workspace))
                    ->markAsRead(),
            ])
            ->sendToDatabase($owner);
    }
}
