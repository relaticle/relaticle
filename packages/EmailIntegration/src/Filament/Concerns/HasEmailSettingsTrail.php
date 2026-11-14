<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;

trait HasEmailSettingsTrail
{
    public function getHeadingStart(): Htmlable
    {
        return new HtmlString(view('filament.app.record-breadcrumb', [
            'url' => EmailAccountsPage::getUrl(),
            'icon' => 'lucide-mail',
            'label' => __('workspaces.tabs.email'),
        ])->render());
    }
}
