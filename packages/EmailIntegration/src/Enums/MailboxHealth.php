<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use App\Contracts\Billing;
use App\Models\Workspace;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;

enum MailboxHealth: string implements HasColor, HasLabel
{
    case Healthy = 'healthy';
    case Stale = 'stale';
    case ReauthRequired = 'reauth_required';
    case Error = 'error';
    case PausedBilling = 'paused_billing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Healthy => __('filament/pages/email-accounts.health.labels.healthy'),
            self::Stale => __('filament/pages/email-accounts.health.labels.stale'),
            self::ReauthRequired => __('filament/pages/email-accounts.health.labels.reauth_required'),
            self::Error => __('filament/pages/email-accounts.health.labels.error'),
            self::PausedBilling => __('filament/pages/email-accounts.health.labels.paused_billing'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Stale, self::PausedBilling => 'warning',
            self::ReauthRequired, self::Error => 'danger',
        };
    }

    public function needsReconnect(): bool
    {
        return match ($this) {
            self::Stale, self::ReauthRequired, self::Error => true,
            self::Healthy, self::PausedBilling => false,
        };
    }

    public function notifiesAdminsAtOnce(): bool
    {
        return $this === self::PausedBilling;
    }

    public function remedyUrl(Workspace $workspace): ?string
    {
        return $this === self::PausedBilling
            ? resolve(Billing::class)->url($workspace)
            : EmailAccountsPage::getUrl(panel: 'app', tenant: $workspace);
    }
}
