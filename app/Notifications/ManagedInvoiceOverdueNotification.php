<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Enums\Plan;
use App\Filament\Pages\Billing;
use App\Models\Notification as NotificationRow;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;

final class ManagedInvoiceOverdueNotification extends InboxNotification
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly Plan $plan,
    ) {}

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function subject(): Workspace
    {
        return $this->workspace;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::System;
    }

    public function groupKey(): string
    {
        return "managed-overdue:{$this->workspace->getKey()}";
    }

    public function visibleInEveryWorkspace(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'workspace_id' => $this->workspace->getKey(),
            'plan' => $this->plan->value,
        ];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $workspace = $row->subject;
        $plan = Plan::tryFrom((string) data_get($row->data, 'plan'))?->marketingLabel() ?? '';

        return FilamentNotification::make((string) $row->getKey())
            ->title(__('billing.managed.overdue_title', ['plan' => $plan]))
            ->body(__('billing.managed.overdue_notification_body', [
                'workspace' => $workspace->name ?? '',
                'plan' => $plan,
                'button' => __('billing.manage.button'),
            ]))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('danger')
            ->actions($workspace instanceof Workspace ? [
                Action::make('billing')
                    ->button()
                    ->label(__('billing.managed.overdue_notification_action'))
                    ->url(Billing::getUrl(panel: 'app', tenant: $workspace))
                    ->markAsRead(),
            ] : []);
    }
}
