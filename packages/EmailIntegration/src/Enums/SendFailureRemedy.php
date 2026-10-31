<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use App\Contracts\Entitlements;
use App\Filament\Pages\Billing;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

enum SendFailureRemedy: string
{
    case Retry = 'retry';

    case Reconnect = 'reconnect';

    case Subscribe = 'subscribe';

    public static function for(Workspace $workspace, ?ConnectedAccount $account): self
    {
        if (! resolve(Entitlements::class)->allowsAccess($workspace->loadMissing('subscriptions'))) {
            return self::Subscribe;
        }

        if ($account?->isSendable() !== true) {
            return self::Reconnect;
        }

        return self::Retry;
    }

    public function url(Workspace $workspace): string
    {
        return match ($this) {
            self::Retry => EmailInboxPage::getUrl(['tab' => EmailPageTab::FAILED->value], panel: 'app', tenant: $workspace),
            self::Reconnect => EmailAccountsPage::getUrl(panel: 'app', tenant: $workspace),
            self::Subscribe => Billing::getUrl(panel: 'app', tenant: $workspace),
        };
    }
}
